<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Entity\Link;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Link>
 */
final class LinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Link::class);
    }

    /**
     * Requeues already-processed links (archived or failed) back to pending so the
     * next `app:archive-pending` run re-fetches them (e.g. to regenerate previews).
     * Returns the count reset.
     */
    /** Reassigns every link of one collection to another (folder merge). Returns the count moved. */
    public function moveAllToCollection(Collection $from, Collection $to): int
    {
        return (int) $this->createQueryBuilder('l')
            ->update()
            ->set('l.collection', ':to')
            ->where('l.collection = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute();
    }

    public function requeueAllForArchive(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->update()
            ->set('l.status', ':pending')
            ->set('l.lastError', 'NULL')
            ->where('l.status IN (:processed)')
            ->setParameter('pending', Link::STATUS_PENDING)
            ->setParameter('processed', [Link::STATUS_DONE, Link::STATUS_FAILED])
            ->getQuery()
            ->execute();
    }

    /**
     * Links to health-check, least-recently-checked first (never-checked come first
     * since their health_checked_at is NULL, which sorts first in ASC on SQLite).
     * After a link is checked its timestamp moves it to the back of the queue, so
     * successive runs advance through the whole set instead of repeating the same rows.
     *
     * $notCheckedSince, when given, excludes links already checked more recently than
     * that instant — a daily cron then spends no requests re-checking fresh links.
     *
     * Vault-protected collections are skipped: their URL is encrypted and reads as a
     * `[locked]` placeholder in a CLI run, so it cannot be fetched. "À trier"
     * (skip_processing) folders are skipped too — health-checking is automatic
     * processing — unless $includeIgnored is true. $limit <= 0 = all.
     *
     * @return list<Link>
     */
    public function findForHealthCheck(int $limit = 100, ?\DateTimeImmutable $notCheckedSince = null, bool $includeIgnored = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.vault IS NULL')
            ->andWhere("(l.url LIKE 'http://%' OR l.url LIKE 'https://%')")
            ->orderBy('l.healthCheckedAt', 'ASC')
            ->addOrderBy('l.id', 'ASC');
        if (!$includeIgnored) {
            $qb->andWhere('c.skipProcessing = false');
        }
        if (null !== $notCheckedSince) {
            $qb->andWhere('(l.healthCheckedAt IS NULL OR l.healthCheckedAt < :since)')
                ->setParameter('since', $notCheckedSince);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Link> dead links (last check was 404/410) for the dashboard, sorted by URL */
    public function findDeadForDashboard(Dashboard $dashboard): array
    {
        return $this->hydrateCards($this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->andWhere('l.healthStatus = :dead')
            ->setParameter('d', $dashboard)
            ->setParameter('dead', Link::HEALTH_DEAD)
            ->orderBy('l.url', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<Link> unreachable links (last check failed at transport level) for the dashboard */
    public function findUnreachableForDashboard(Dashboard $dashboard): array
    {
        return $this->hydrateCards($this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->andWhere('l.healthStatus = :err')
            ->setParameter('d', $dashboard)
            ->setParameter('err', Link::HEALTH_ERROR)
            ->orderBy('l.url', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Unreachable links clustered by host, keeping only hosts that recur at
     * least twice — so a whole dead domain can be wiped in one click. Hosts are
     * lower-cased with a leading "www." stripped; biggest clusters first.
     *
     * @return list<array{host: string, count: int}>
     */
    public function findUnreachableDomainClusters(Dashboard $dashboard): array
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.url AS url')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->andWhere('l.healthStatus = :err')
            ->setParameter('d', $dashboard)
            ->setParameter('err', Link::HEALTH_ERROR)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $host = $this->hostOf($row['url']);
            if ('' !== $host) {
                $counts[$host] = ($counts[$host] ?? 0) + 1;
            }
        }

        $clusters = [];
        foreach ($counts as $host => $count) {
            if ($count >= 2) {
                $clusters[] = ['host' => $host, 'count' => $count];
            }
        }
        usort($clusters, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['host'], $b['host']));

        return $clusters;
    }

    /**
     * Deletes every unreachable link of $dashboard whose host matches $host
     * (normalised the same way as findUnreachableDomainClusters). Returns the
     * number removed. Uses em->remove per row so the ArchiveAsset cascade and
     * any lifecycle listeners still fire.
     */
    public function deleteUnreachableForHost(Dashboard $dashboard, string $host): int
    {
        $host = $this->normalizeHost($host);
        if ('' === $host) {
            return 0;
        }

        /** @var list<Link> $links */
        $links = $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->andWhere('l.healthStatus = :err')
            ->setParameter('d', $dashboard)
            ->setParameter('err', Link::HEALTH_ERROR)
            ->getQuery()
            ->getResult();

        $em = $this->getEntityManager();
        $deleted = 0;
        foreach ($links as $link) {
            if ($this->hostOf($link->getUrl()) === $host) {
                $em->remove($link);
                ++$deleted;
            }
        }
        if ($deleted > 0) {
            $em->flush();
        }

        return $deleted;
    }

    /** Lower-cased host of a URL, with a leading "www." stripped; '' if none. */
    private function hostOf(string $url): string
    {
        return $this->normalizeHost((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Groups of links that resolve to the same URL once normalised — lower-cased,
     * stripped of trailing slashes and of a leading "www." in the host — so the
     * dead-links page can surface redundant copies and let the user delete the
     * ones they don't want. Only groups with more than one member are returned;
     * groups are ordered by URL, and each group keeps its members together so
     * their folders are comparable.
     *
     * @return list<list<Link>>
     */
    public function findDuplicatesForDashboard(Dashboard $dashboard): array
    {
        /** @var list<Link> $all */
        $all = $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->orderBy('l.url', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<string, list<Link>> $groups */
        $groups = [];
        foreach ($all as $link) {
            $key = strtolower(rtrim($link->getUrl(), '/'));
            // Treat "https://www.example.com" and "https://example.com" as the same.
            $key = preg_replace('~^([a-z][a-z0-9+.-]*://)www\.~', '$1', $key) ?? $key;
            $groups[$key][] = $link;
        }

        $duplicates = [];
        foreach ($groups as $group) {
            if (\count($group) > 1) {
                $duplicates[] = $group;
            }
        }

        if ([] !== $duplicates) {
            $this->hydrateCards(array_merge(...$duplicates));
        }

        return $duplicates;
    }

    public function countDeadForDashboard(Dashboard $dashboard): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->andWhere('l.healthStatus = :dead')
            ->setParameter('d', $dashboard)
            ->setParameter('dead', Link::HEALTH_DEAD)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Total links still waiting to be indexed (same filter as findPendingArchive). */
    public function countPendingArchive(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.collection', 'c')
            ->andWhere('l.status = :s')
            ->andWhere('c.skipProcessing = false')
            ->setParameter('s', Link::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Link> */
    public function findPendingArchive(int $limit = 20): array
    {
        // INNER JOIN on the collection so a link orphaned by a deleted collection
        // is skipped rather than crashing the whole batch when its proxy loads.
        return $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('l.status = :s')
            ->andWhere('c.skipProcessing = false')
            ->setParameter('s', Link::STATUS_PENDING)
            // Newest first: a link you just added gets its title/preview before the
            // older backlog.
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Requeues links whose AI tagging failed (ai_status = failed) back to pending
     * so the next `app:ai-tag-pending` run retries them. Returns the count reset.
     */
    public function resetFailedAiStatus(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->update()
            ->set('l.aiStatus', ':pending')
            ->set('l.lastError', 'NULL')
            ->where('l.aiStatus = :failed')
            ->setParameter('pending', Link::AI_PENDING)
            ->setParameter('failed', Link::AI_FAILED)
            ->getQuery()
            ->execute();
    }

    /** @return list<Link> */
    public function findPendingAiEnrichment(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('l.aiStatus = :ai AND l.status = :done')
            ->andWhere('c.skipProcessing = false')
            ->setParameter('ai', Link::AI_PENDING)
            ->setParameter('done', Link::STATUS_DONE)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Link> archived links still awaiting an AI summary */
    public function findPendingSummary(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('l.summaryStatus = :s AND l.status = :done')
            ->andWhere('c.skipProcessing = false')
            ->setParameter('s', Link::SUMMARY_PENDING)
            ->setParameter('done', Link::STATUS_DONE)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Requeues links whose AI summarization failed. Returns the count reset. */
    public function resetFailedSummaryStatus(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->update()
            ->set('l.summaryStatus', ':pending')
            ->set('l.lastError', 'NULL')
            ->where('l.summaryStatus = :failed')
            ->setParameter('pending', Link::SUMMARY_PENDING)
            ->setParameter('failed', Link::SUMMARY_FAILED)
            ->getQuery()
            ->execute();
    }

    /**
     * @param 'ASC'|'DESC' $order 'DESC' for the "recent links" widget (newest first),
     *                            'ASC' to preserve insertion/import order (Firefox order)
     *
     * @return list<Link>
     */
    public function findForDashboard(Dashboard $dashboard, int $limit = 50, ?int $cursor = null, string $order = 'DESC'): array
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->orderBy('l.id', $order)
            ->setMaxResults($limit);
        if ($cursor !== null && $cursor > 0) {
            $qb->andWhere('l.id '.('ASC' === $order ? '>' : '<').' :cursor')->setParameter('cursor', $cursor);
        }

        return $this->hydrateCards($qb->getQuery()->getResult());
    }

    /** Total number of links in a dashboard (all collections). */
    public function countForDashboard(Dashboard $dashboard): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Records a click via a direct UPDATE (bypasses the encrypt lifecycle listener). */
    public function registerClick(int $id): void
    {
        $this->createQueryBuilder('l')
            ->update()
            ->set('l.lastClickedAt', ':now')
            ->set('l.clickCount', 'l.clickCount + 1')
            ->where('l.id = :id')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
    }

    /** @return list<Link> favorite links of a dashboard, newest first */
    public function findFavoritesForDashboard(Dashboard $dashboard, int $limit = 12): array
    {
        return $this->hydrateCards($this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d AND l.favorite = true')
            ->setParameter('d', $dashboard)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /** @return list<Link> most recently clicked links of a dashboard */
    public function findRecentlyClicked(Dashboard $dashboard, int $limit = 8): array
    {
        return $this->hydrateCards($this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d AND l.lastClickedAt IS NOT NULL')
            ->setParameter('d', $dashboard)
            ->orderBy('l.lastClickedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /** @return list<Link> most clicked links of a dashboard */
    public function findMostClicked(Dashboard $dashboard, int $limit = 8): array
    {
        return $this->hydrateCards($this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d AND l.clickCount > 0')
            ->setParameter('d', $dashboard)
            ->orderBy('l.clickCount', 'DESC')
            ->addOrderBy('l.lastClickedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /** @return list<Link> most recently added links of a collection */
    public function findRecentForCollection(Collection $collection, int $limit = 4): array
    {
        return $this->hydrateCards($this->createQueryBuilder('l')
            ->andWhere('l.collection = :c')
            ->setParameter('c', $collection)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * @param int|null $limit null = no limit (show the whole collection)
     *
     * @return list<Link>
     */
    public function findForCollection(Collection $collection, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.collection = :c')
            ->setParameter('c', $collection)
            ->orderBy('l.clickCount', 'DESC')
            ->addOrderBy('l.id', 'DESC');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $this->hydrateCards($qb->getQuery()->getResult());
    }

    /**
     * @param int|null $limit null = no limit
     *
     * @return list<Link>
     */
    public function findForTag(Tag $tag, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.tags', 't')
            ->andWhere('t = :t')
            ->setParameter('t', $tag)
            ->orderBy('l.id', 'ASC');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $this->hydrateCards($qb->getQuery()->getResult());
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Link>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->hydrateCards($this->createQueryBuilder('l')
            ->andWhere('l.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Batch-load the tags and assets of the given links so rendering a list of
     * cards (`links/_card.html.twig` reads `link.tags` and `link.getAsset()`)
     * does not trigger ~3 lazy queries per card. Two extra queries total instead
     * of 3×N. Kept as separate joins to avoid a tags×assets cartesian product.
     * The links are already managed, so Doctrine populates their collections in
     * the identity map; the returned array preserves the input order.
     *
     * @param list<Link> $links
     *
     * @return list<Link>
     */
    private function hydrateCards(array $links): array
    {
        if ([] === $links) {
            return $links;
        }

        $this->createQueryBuilder('l')
            ->leftJoin('l.tags', 't')->addSelect('t')
            ->andWhere('l IN (:links)')->setParameter('links', $links)
            ->getQuery()->getResult();

        $this->createQueryBuilder('l')
            ->leftJoin('l.assets', 'a')->addSelect('a')
            ->andWhere('l IN (:links)')->setParameter('links', $links)
            ->getQuery()->getResult();

        return $links;
    }
}
