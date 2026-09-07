<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LinkRepository::class)]
#[ORM\Table(name: 'links')]
#[ORM\Index(columns: ['collection_id'])]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['ai_status'])]
#[ORM\Index(columns: ['summary_status'])]
class Link
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ARCHIVING = 'archiving';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const AI_PENDING = 'pending';
    public const AI_DONE = 'done';
    public const AI_FAILED = 'failed';
    public const AI_SKIP = 'skip';

    public const SUMMARY_PENDING = 'pending';
    public const SUMMARY_DONE = 'done';
    public const SUMMARY_FAILED = 'failed';
    public const SUMMARY_SKIP = 'skip';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textContent = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $iconPath = null;

    /** Archive-relative path to the downloaded preview image, e.g. "12/preview.jpg". */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $previewImage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collection $collection;

    /** @var DoctrineCollection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'link_tag')]
    private DoctrineCollection $tags;

    /** @var DoctrineCollection<int, ArchiveAsset> */
    #[ORM\OneToMany(targetEntity: ArchiveAsset::class, mappedBy: 'link')]
    private DoctrineCollection $assets;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 16)]
    private string $aiStatus = self::AI_PENDING;

    #[ORM\Column(length: 16)]
    private string $summaryStatus = self::SUMMARY_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiSummary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private bool $isEncrypted = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(string $url, Collection $collection)
    {
        $this->url = $url;
        $this->collection = $collection;
        $this->tags = new ArrayCollection();
        $this->assets = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getTextContent(): ?string
    {
        return $this->textContent;
    }

    public function setTextContent(?string $textContent): void
    {
        $this->textContent = $textContent;
    }

    public function getIconPath(): ?string
    {
        return $this->iconPath;
    }

    public function setIconPath(?string $iconPath): void
    {
        $this->iconPath = $iconPath;
    }

    public function getPreviewImage(): ?string
    {
        return $this->previewImage;
    }

    public function setPreviewImage(?string $previewImage): void
    {
        $this->previewImage = $previewImage;
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function setCollection(Collection $collection): void
    {
        $this->collection = $collection;
    }

    /** @return list<Tag> */
    public function getTags(): array
    {
        return array_values($this->tags->toArray());
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    /** @return list<ArchiveAsset> */
    public function getAssets(): array
    {
        return array_values($this->assets->toArray());
    }

    /** Returns the archived asset of the given kind (pdf/screenshot/…), or null. */
    public function getAsset(string $kind): ?ArchiveAsset
    {
        foreach ($this->assets as $asset) {
            if ($asset->getKind() === $kind) {
                return $asset;
            }
        }

        return null;
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getAiStatus(): string
    {
        return $this->aiStatus;
    }

    public function setAiStatus(string $aiStatus): void
    {
        $this->aiStatus = $aiStatus;
    }

    public function getSummaryStatus(): string
    {
        return $this->summaryStatus;
    }

    public function setSummaryStatus(string $summaryStatus): void
    {
        $this->summaryStatus = $summaryStatus;
    }

    public function getAiSummary(): ?string
    {
        return $this->aiSummary;
    }

    public function setAiSummary(?string $aiSummary): void
    {
        $this->aiSummary = $aiSummary;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }

    public function isEncrypted(): bool
    {
        return $this->isEncrypted;
    }

    public function setEncrypted(bool $isEncrypted): void
    {
        $this->isEncrypted = $isEncrypted;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): void
    {
        $this->archivedAt = $archivedAt;
    }
}
