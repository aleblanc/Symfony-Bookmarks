<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Entity\Link;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Collection>
 */
final class CollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Collection::class);
    }

    /** @return list<Collection> */
    public function findForDashboard(Dashboard $dashboard): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Collections of a dashboard, each with its link count — for the sidebar.
     *
     * @return list<array{collection: Collection, count: int}>
     */
    public function findForDashboardWithCounts(Dashboard $dashboard): array
    {
        /** @var list<array{collection: Collection, cnt: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c AS collection', 'COUNT(l.id) AS cnt')
            ->leftJoin(Link::class, 'l', 'WITH', 'l.collection = c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['collection' => $row['collection'], 'count' => (int) $row['cnt']],
            $rows,
        );
    }
}
