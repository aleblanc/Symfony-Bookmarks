<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\LinkResource;
use App\Entity\Link;
use App\Entity\Tag;
use App\Repository\LinkRepository;

/**
 * Reads links for the API v2 (vault + "à trier" collections excluded).
 *
 * @implements ProviderInterface<LinkResource>
 */
final class LinkProvider implements ProviderInterface
{
    public function __construct(private readonly LinkRepository $links)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(self::toResource(...), $this->links->findApiList());
        }

        $id = isset($uriVariables['id']) ? (int) $uriVariables['id'] : 0;
        $link = $this->links->find($id);
        // Item not found, or lives in a vault collection → hidden from the API.
        if (null === $link || null !== $link->getCollection()->getVault()) {
            return null;
        }

        return self::toResource($link);
    }

    public static function toResource(Link $link): LinkResource
    {
        $collection = $link->getCollection();

        $resource = new LinkResource();
        $resource->id = $link->getId();
        $resource->url = $link->getUrl();
        $resource->name = $link->getName();
        $resource->description = $link->getDescription();
        $resource->tags = array_values(array_map(static fn (Tag $t): string => $t->getName(), $link->getTags()));
        $resource->collectionId = $collection->getId();
        $resource->collectionName = $collection->getName();
        $resource->dashboardId = $collection->getDashboard()->getId();
        $resource->health = $link->getHealthStatus();
        $resource->httpStatus = $link->getHttpStatus();
        $resource->createdAt = $link->getCreatedAt()->format(\DATE_ATOM);

        return $resource;
    }
}
