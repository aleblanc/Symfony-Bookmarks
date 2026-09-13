<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TagResource;
use App\Entity\Tag;
use App\Repository\TagRepository;

/**
 * @implements ProviderInterface<TagResource>
 */
final class TagProvider implements ProviderInterface
{
    public function __construct(private readonly TagRepository $tags)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            /** @var list<Tag> $all */
            $all = $this->tags->findBy([], ['name' => 'ASC']);

            return array_map(self::toResource(...), $all);
        }

        $id = isset($uriVariables['id']) ? (int) $uriVariables['id'] : 0;
        $tag = $this->tags->find($id);

        return null !== $tag ? self::toResource($tag) : null;
    }

    public static function toResource(Tag $tag): TagResource
    {
        $resource = new TagResource();
        $resource->id = $tag->getId();
        $resource->name = $tag->getName();
        $resource->dashboardId = $tag->getDashboard()->getId();

        return $resource;
    }
}
