<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CollectionResource;
use App\Entity\Collection;
use App\Repository\CollectionRepository;

/**
 * @implements ProviderInterface<CollectionResource>
 */
final class CollectionProvider implements ProviderInterface
{
    public function __construct(private readonly CollectionRepository $collections)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(self::toResource(...), $this->collections->findApiList());
        }

        $id = isset($uriVariables['id']) ? (int) $uriVariables['id'] : 0;
        $collection = $this->collections->find($id);
        if (null === $collection || null !== $collection->getVault()) {
            return null;
        }

        return self::toResource($collection);
    }

    public static function toResource(Collection $collection): CollectionResource
    {
        $resource = new CollectionResource();
        $resource->id = $collection->getId();
        $resource->name = $collection->getName();
        $resource->description = $collection->getDescription();
        $resource->color = $collection->getColor();
        $resource->icon = $collection->getIcon();
        $resource->parentId = $collection->getParent()?->getId();
        $resource->dashboardId = $collection->getDashboard()->getId();
        $resource->skipProcessing = $collection->isSkipProcessing();
        $resource->createdAt = $collection->getCreatedAt()->format(\DATE_ATOM);

        return $resource;
    }
}
