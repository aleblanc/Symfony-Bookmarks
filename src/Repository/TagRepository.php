<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dashboard;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
final class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return list<Tag> */
    public function findForDashboard(Dashboard $dashboard): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tags in use in a dashboard, each with its link count — for the sidebar.
     *
     * @return list<array{tag: Tag, count: int}>
     */
    public function findForDashboardWithCounts(Dashboard $dashboard): array
    {
        /** @var list<array{tag: Tag, cnt: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t AS tag', 'COUNT(l.id) AS cnt')
            ->leftJoin('t.links', 'l')
            ->andWhere('t.dashboard = :d')
            ->setParameter('d', $dashboard)
            ->groupBy('t.id')
            ->having('COUNT(l.id) > 0')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['tag' => $row['tag'], 'count' => (int) $row['cnt']],
            $rows,
        );
    }

    public function findOrCreate(string $name, Dashboard $dashboard): Tag
    {
        $existing = $this->findOneBy(['dashboard' => $dashboard, 'name' => strtolower(trim($name))]);
        if ($existing !== null) {
            return $existing;
        }
        $tag = new Tag($name, $dashboard);
        $this->getEntityManager()->persist($tag);

        return $tag;
    }
}
