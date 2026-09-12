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

    /**
     * The fallback collection used when a link is created without an explicit
     * target (e.g. the browser extension): the oldest collection by id.
     */
    public function findDefaultCollection(): ?Collection
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * A root-level (no parent) collection of $dashboard by exact name, or null.
     * Used by the paste-a-list import to reuse an existing target folder before
     * creating a new one.
     */
    public function findRootByName(string $name, Dashboard $dashboard): ?Collection
    {
        return $this->findOneBy(['name' => $name, 'parent' => null, 'dashboard' => $dashboard]);
    }

    /** @return list<Collection> */
    public function findForDashboard(Dashboard $dashboard): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All collections of a dashboard flattened in hierarchical (depth-first) order:
     * each parent immediately followed by its children, siblings in display order.
     * Used for the parent <select> so a deep child never appears before its parent.
     *
     * @return list<Collection>
     */
    public function findForDashboardTreeOrder(Dashboard $dashboard): array
    {
        $all = $this->findForDashboard($dashboard);

        /** @var array<int, list<Collection>> $childrenOf */
        $childrenOf = [];
        foreach ($all as $c) {
            $childrenOf[$c->getParent()?->getId() ?? 0][] = $c;
        }

        $flat = [];
        $walk = static function (int $parentId) use (&$walk, $childrenOf, &$flat): void {
            foreach ($childrenOf[$parentId] ?? [] as $c) {
                $flat[] = $c;
                $walk((int) $c->getId());
            }
        };
        $walk(0);

        return $flat;
    }

    /** @return list<Collection> root (top-level) collections only, in display order */
    public function findRootsForDashboard(Dashboard $dashboard): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.dashboard = :d AND c.parent IS NULL')
            ->setParameter('d', $dashboard)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All descendants (children, grandchildren, …) of a collection. Used to
     * forbid moving a folder into its own subtree (which would create a cycle).
     *
     * @return list<Collection>
     */
    public function findDescendants(Collection $collection): array
    {
        $descendants = [];
        foreach ($this->findChildren($collection) as $child) {
            $descendants[] = $child;
            foreach ($this->findDescendants($child) as $deeper) {
                $descendants[] = $deeper;
            }
        }

        return $descendants;
    }

    /**
     * Siblings of a collection (same parent, same dashboard) in display order,
     * including the collection itself. Used by the up/down reorder action.
     *
     * @return list<Collection>
     */
    public function findSiblings(Collection $collection): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.dashboard = :d')
            ->setParameter('d', $collection->getDashboard())
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC');
        if (null === $collection->getParent()) {
            $qb->andWhere('c.parent IS NULL');
        } else {
            $qb->andWhere('c.parent = :p')->setParameter('p', $collection->getParent());
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Collection> direct children of a collection */
    public function findChildren(Collection $parent): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent = :p')
            ->setParameter('p', $parent)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
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
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
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
