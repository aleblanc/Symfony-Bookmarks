<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\TagProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/** API v2 read-only view of a tag (tags are created by AI/links, not the API). */
#[ApiResource(
    shortName: 'Tag',
    provider: TagProvider::class,
    normalizationContext: ['groups' => ['tag:read']],
    operations: [
        new GetCollection(paginationEnabled: false),
        new Get(),
    ],
)]
class TagResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['tag:read'])]
    public ?int $id = null;

    #[Groups(['tag:read'])]
    public string $name = '';

    #[Groups(['tag:read'])]
    public ?int $dashboardId = null;
}
