<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArchiveAssetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArchiveAssetRepository::class)]
#[ORM\Table(name: 'archive_assets')]
class ArchiveAsset
{
    public const KIND_SINGLEFILE = 'singlefile';
    public const KIND_SCREENSHOT = 'screenshot';
    public const KIND_PDF = 'pdf';
    public const KIND_RAW_HTML = 'raw_html';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Link $link;

    #[ORM\Column(length: 32)]
    private string $kind;

    #[ORM\Column(length: 500)]
    private string $relativePath;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Link $link, string $kind, string $relativePath, int $sizeBytes)
    {
        $this->link = $link;
        $this->kind = $kind;
        $this->relativePath = $relativePath;
        $this->sizeBytes = $sizeBytes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLink(): Link
    {
        return $this->link;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): void
    {
        $this->sizeBytes = $sizeBytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
