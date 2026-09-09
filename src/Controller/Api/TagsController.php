<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TagsController extends AbstractApiController
{
    public function __construct(private readonly TagRepository $tags)
    {
    }

    #[Route('/api/v1/tags', name: 'api_tags_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = [];
        foreach ($this->tags->findAll() as $tag) {
            /* @var Tag $tag */
            $items[] = ['id' => $tag->getId(), 'name' => $tag->getName()];
        }

        return $this->ok($items);
    }
}
