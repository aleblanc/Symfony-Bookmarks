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

    /** @return list<Collection> direct children of a collection */
    public function findChildren(Collection $parent): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent = :p')
            ->setParameter('p', $parent)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Full nested folder tree of a dashboard, each node carrying its direct link count.
     *
     * @return list<array{collection: Collection, count: int, children: array<int, mixed>}>
     */
    public function findTreeForDashboard(Dashboard $dashboard): array
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

        // Accessing getParent() only reads the proxy id (no extra query).
        $childrenOf = [];
        foreach ($rows as $row) {
            $parentId = $row['collection']->getParent()?->getId() ?? 0;
            $childrenOf[$parentId][] = $row;
        }

        $build = static function (int $parentId) use (&$build, $childrenOf): array {
            $nodes = [];
            foreach ($childrenOf[$parentId] ?? [] as $row) {
                $nodes[] = [
                    'collection' => $row['collection'],
                    'count' => (int) $row['cnt'],
                    'children' => $build((int) $row['collection']->getId()),
                ];
            }

            return $nodes;
        };

        return $build(0);
    }
}
