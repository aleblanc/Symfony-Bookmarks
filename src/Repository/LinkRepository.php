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

    /** @return list<Link> */
    public function findPendingArchive(int $limit = 20): array
    {
        // INNER JOIN on the collection so a link orphaned by a deleted collection
        // is skipped rather than crashing the whole batch when its proxy loads.
        return $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('l.status = :s')
            ->setParameter('s', Link::STATUS_PENDING)
            ->orderBy('l.id', 'ASC')
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

        return $qb->getQuery()->getResult();
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
            ->orderBy('l.id', 'ASC');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
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

        return $qb->getQuery()->getResult();
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

        return $this->createQueryBuilder('l')
            ->andWhere('l.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
