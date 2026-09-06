<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Link;
use App\Entity\Tag;
use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
use App\Service\Search\LinkSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LinksController extends AbstractApiController
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
        private readonly LinkSearch $search,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/v1/links', name: 'api_links_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $cursor = (int) ($request->query->get('cursor') ?? 0);
        $collectionId = $request->query->get('collectionId');
        $tagId = $request->query->get('tagId');
        $q = trim((string) $request->query->get('searchQueryString', ''));

        if ('' !== $q) {
            $ids = $this->search->ftsIds($q);
            if ([] === $ids) {
                return $this->ok([]);
            }
            $rows = $this->links->findByIds($ids);
        } elseif (null !== $collectionId) {
            $collection = $this->collections->find((int) $collectionId);
            $rows = null === $collection ? [] : $this->links->findForCollection($collection);
        } else {
            $rows = $this->links->findAll();
        }

        $items = [];
        foreach ($rows as $link) {
            /** @var Link $link */
            if ($cursor > 0 && $link->getId() !== null && $link->getId() >= $cursor) {
                continue;
            }
            if (null !== $tagId) {
                $tagIds = array_map(static fn (Tag $t): ?int => $t->getId(), $link->getTags());
                if (!\in_array((int) $tagId, $tagIds, true)) {
                    continue;
                }
            }
            $items[] = $this->serialize($link);
        }

        return $this->ok(\array_slice($items, 0, 20));
    }

    #[Route('/api/v1/links', name: 'api_links_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return $this->fail('invalid json');
        }
        $url = trim((string) ($body['url'] ?? ''));
        if ('' === $url || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return $this->fail('valid url required');
        }
        $collectionId = $body['collection']['id'] ?? $body['collectionId'] ?? null;
        $collection = null !== $collectionId
            ? $this->collections->find((int) $collectionId)
            : $this->collections->findOneBy([], ['id' => 'ASC']);
        if (null === $collection) {
            return $this->fail('no collection available');
        }
        $link = new Link($url, $collection);
        if (!empty($body['name'])) {
            $link->setName((string) $body['name']);
        }
        if (!empty($body['description'])) {
            $link->setDescription((string) $body['description']);
        }
        $this->em->persist($link);
        $this->em->flush();

        return $this->ok($this->serialize($link), 201);
    }

    #[Route('/api/v1/links/{id}', name: 'api_links_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return $this->fail('invalid json');
        }
        if (\array_key_exists('name', $body)) {
            $link->setName(null === $body['name'] ? null : (string) $body['name']);
        }
        if (\array_key_exists('description', $body)) {
            $link->setDescription(null === $body['description'] ? null : (string) $body['description']);
        }
        if (\array_key_exists('url', $body)) {
            $link->setUrl((string) $body['url']);
        }
        $this->em->flush();

        return $this->ok($this->serialize($link));
    }

    #[Route('/api/v1/links/{id}', name: 'api_links_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $this->em->remove($link);
        $this->em->flush();

        return $this->ok(['deleted' => true]);
    }

    #[Route('/api/v1/archives/{id}', name: 'api_archives_retrigger', methods: ['POST'])]
    public function reArchive(int $id): JsonResponse
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $link->setStatus(Link::STATUS_PENDING);
        $link->setAiStatus(Link::AI_PENDING);
        $link->setLastError(null);
        $this->em->flush();

        return $this->ok(['queued' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Link $link): array
    {
        $collection = $link->getCollection();
        $tags = [];
        foreach ($link->getTags() as $tag) {
            $tags[] = ['id' => $tag->getId(), 'name' => $tag->getName()];
        }

        return [
            'id' => $link->getId(),
            'name' => $link->getName(),
            'url' => $link->getUrl(),
            'description' => $link->getDescription(),
            'type' => 'url',
            'createdAt' => $link->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $link->getCreatedAt()->format(\DATE_ATOM),
            'collection' => [
                'id' => $collection->getId(),
                'name' => $collection->getName(),
                'color' => $collection->getColor(),
            ],
            'tags' => $tags,
            'pinnedBy' => [],
            'image' => null,
            'pdf' => null,
            'readable' => null !== $link->getTextContent() ? '/links/'.$link->getId().'/readable' : null,
            'monolith' => null,
            'textContent' => $link->getTextContent(),
        ];
    }
}
