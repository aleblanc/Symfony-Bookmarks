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
use App\State\LinkProcessor;
use App\State\LinkProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * API v2 representation of a bookmark. Decoupled from the Doctrine entity so the
 * API never touches vault encryption or the entity's required constructor; the
 * provider/processor map to/from App\Entity\Link.
 */
#[ApiResource(
    shortName: 'Link',
    provider: LinkProvider::class,
    processor: LinkProcessor::class,
    normalizationContext: ['groups' => ['link:read']],
    denormalizationContext: ['groups' => ['link:write']],
    operations: [
        new GetCollection(paginationEnabled: false),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
)]
class LinkResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['link:read'])]
    public ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['http', 'https', 'ftp', 'ftps'])]
    #[Assert\Length(max: 2048)]
    #[Groups(['link:read', 'link:write'])]
    public string $url = '';

    #[Assert\Length(max: 500)]
    #[Groups(['link:read', 'link:write'])]
    public ?string $name = null;

    #[Groups(['link:read', 'link:write'])]
    public ?string $description = null;

    /** @var list<string> */
    #[Groups(['link:read'])]
    public array $tags = [];

    /** Target collection (write) / current collection (read). */
    #[Groups(['link:read', 'link:write'])]
    public ?int $collectionId = null;

    #[Groups(['link:read'])]
    public ?string $collectionName = null;

    #[Groups(['link:read'])]
    public ?int $dashboardId = null;

    #[Groups(['link:read'])]
    public ?string $health = null;

    #[Groups(['link:read'])]
    public ?int $httpStatus = null;

    #[Groups(['link:read'])]
    public ?string $createdAt = null;

    #[Groups(['link:read'])]
    public ?string $updatedAt = null;
}
