<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\DashboardResource;
use App\Entity\Dashboard;
use App\Repository\DashboardRepository;

/**
 * @implements ProviderInterface<DashboardResource>
 */
final class DashboardProvider implements ProviderInterface
{
    public function __construct(private readonly DashboardRepository $dashboards)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(self::toResource(...), $this->dashboards->findAllOrdered());
        }

        $id = isset($uriVariables['id']) ? (int) $uriVariables['id'] : 0;
        $dashboard = $this->dashboards->find($id);

        return null !== $dashboard ? self::toResource($dashboard) : null;
    }

    public static function toResource(Dashboard $dashboard): DashboardResource
    {
        $resource = new DashboardResource();
        $resource->id = $dashboard->getId();
        $resource->name = $dashboard->getName();
        $resource->color = $dashboard->getColor();
        $resource->createdAt = $dashboard->getCreatedAt()->format(\DATE_ATOM);

        return $resource;
    }
}
