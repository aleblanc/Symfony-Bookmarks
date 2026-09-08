<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArchiveAsset;
use App\Entity\Link;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArchiveAsset>
 */
final class ArchiveAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArchiveAsset::class);
    }

    /** @return list<ArchiveAsset> */
    public function findForLink(Link $link): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.link = :l')
            ->setParameter('l', $link)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<ArchiveAsset> */
    public function findByKind(string $kind): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.kind = :k')
            ->setParameter('k', $kind)
            ->orderBy('a.sizeBytes', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
