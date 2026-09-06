<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(columns: ['dashboard_id', 'name'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dashboard $dashboard;

    /**
     * Inverse side of Link::$tags (existing link_tag join table) — read-only,
     * used to count how many links carry the tag.
     *
     * @var DoctrineCollection<int, Link>
     */
    #[ORM\ManyToMany(targetEntity: Link::class, mappedBy: 'tags')]
    private DoctrineCollection $links;

    public function __construct(string $name, Dashboard $dashboard)
    {
        $this->name = strtolower(trim($name));
        $this->dashboard = $dashboard;
        $this->links = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDashboard(): Dashboard
    {
        return $this->dashboard;
    }
}
