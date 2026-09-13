<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\DashboardProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/** API v2 read-only view of a dashboard (Perso / Pro …). */
#[ApiResource(
    shortName: 'Dashboard',
    provider: DashboardProvider::class,
    normalizationContext: ['groups' => ['dashboard:read']],
    operations: [
        new GetCollection(paginationEnabled: false),
        new Get(),
    ],
)]
class DashboardResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['dashboard:read'])]
    public ?int $id = null;

    #[Groups(['dashboard:read'])]
    public string $name = '';

    #[Groups(['dashboard:read'])]
    public ?string $color = null;

    #[Groups(['dashboard:read'])]
    public ?string $createdAt = null;
}
