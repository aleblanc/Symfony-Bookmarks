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

    /** @return list<Link> */
    public function findPendingArchive(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :s')
            ->setParameter('s', Link::STATUS_PENDING)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Link> */
    public function findPendingAiEnrichment(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.aiStatus = :ai AND l.status = :done')
            ->setParameter('ai', Link::AI_PENDING)
            ->setParameter('done', Link::STATUS_DONE)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Link> */
    public function findForDashboard(Dashboard $dashboard, int $limit = 50, ?int $cursor = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.collection', 'c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit);
        if ($cursor !== null && $cursor > 0) {
            $qb->andWhere('l.id < :cursor')->setParameter('cursor', $cursor);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Link> */
    public function findForCollection(Collection $collection, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.collection = :c')
            ->setParameter('c', $collection)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Link> */
    public function findForTag(Tag $tag, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.tags', 't')
            ->andWhere('t = :t')
            ->setParameter('t', $tag)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
