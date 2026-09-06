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
