<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\CollectionProcessor;
use App\State\CollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** API v2 representation of a collection (folder). Vault collections are hidden. */
#[ApiResource(
    shortName: 'Collection',
    provider: CollectionProvider::class,
    processor: CollectionProcessor::class,
    normalizationContext: ['groups' => ['collection:read']],
    denormalizationContext: ['groups' => ['collection:write']],
    operations: [
        new GetCollection(paginationEnabled: false),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
)]
class CollectionResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['collection:read'])]
    public ?int $id = null;

    #[Assert\NotBlank]
    #[Groups(['collection:read', 'collection:write'])]
    public string $name = '';

    #[Groups(['collection:read', 'collection:write'])]
    public ?string $description = null;

    #[Groups(['collection:read', 'collection:write'])]
    public ?string $color = null;

    #[Groups(['collection:read', 'collection:write'])]
    public ?string $icon = null;

    #[Groups(['collection:read', 'collection:write'])]
    public ?int $parentId = null;

    /** Target dashboard on create (ignored on update). */
    #[Groups(['collection:read', 'collection:write'])]
    public ?int $dashboardId = null;

    #[Groups(['collection:read', 'collection:write'])]
    public bool $skipProcessing = false;

    #[Groups(['collection:read'])]
    public ?string $createdAt = null;
}
