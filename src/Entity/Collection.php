<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CollectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CollectionRepository::class)]
#[ORM\Table(name: 'collections')]
class Collection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $description = null;

    #[ORM\Column(length: 16)]
    #[Assert\CssColor([Assert\CssColor::HEX_LONG, Assert\CssColor::HEX_SHORT])]
    private string $color = '#0ea5e9';

    #[ORM\Column(length: 4)]
    private string $icon = 'BM';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dashboard $dashboard;

    /** Parent collection for nesting (null = top-level folder). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?Collection $parent = null;

    #[ORM\ManyToOne(targetEntity: Vault::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vault $vault = null;

    /**
     * "À trier" / inbox folder: its links are excluded from all automatic
     * processing (archiving, favicon, AI tagging, AI summary). Move a link out
     * (or untick this flag) and the pending queues pick it up again.
     */
    #[ORM\Column(name: 'skip_processing', type: 'boolean', options: ['default' => false])]
    private bool $skipProcessing = false;

    /** Manual sort order among siblings (lower = first); name breaks ties. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, Dashboard $dashboard)
    {
        $this->name = $name;
        $this->dashboard = $dashboard;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): void
    {
        $this->color = $color;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = $icon;
    }

    public function getDashboard(): Dashboard
    {
        return $this->dashboard;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Ancestor chain from the top-level folder down to (but excluding) this one.
     *
     * @return list<Collection>
     */
    public function getAncestors(): array
    {
        $chain = [];
        for ($p = $this->parent; null !== $p; $p = $p->getParent()) {
            $chain[] = $p;
        }

        return array_reverse($chain);
    }

    public function getVault(): ?Vault
    {
        return $this->vault;
    }

    public function setVault(?Vault $vault): void
    {
        $this->vault = $vault;
    }

    public function isVaultProtected(): bool
    {
        return $this->vault !== null;
    }

    public function isSkipProcessing(): bool
    {
        return $this->skipProcessing;
    }

    public function setSkipProcessing(bool $skipProcessing): void
    {
        $this->skipProcessing = $skipProcessing;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
