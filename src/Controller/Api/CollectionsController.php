<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Collection;
use App\Repository\CollectionRepository;
use App\Repository\DashboardRepository;
use App\Service\CurrentDashboard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CollectionsController extends AbstractApiController
{
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly DashboardRepository $dashboards,
        private readonly CurrentDashboard $current,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/v1/collections', name: 'api_collections_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = [];
        foreach ($this->collections->findAll() as $collection) {
            $items[] = $this->serialize($collection);
        }

        return $this->ok($items);
    }

    #[Route('/api/v1/collections', name: 'api_collections_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return $this->fail('invalid json');
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ('' === $name) {
            return $this->fail('name required');
        }
        $dashboard = $this->current->tryGet() ?? $this->dashboards->find(1);
        if (null === $dashboard) {
            return $this->fail('no dashboard available', 500);
        }
        $collection = new Collection($name, $dashboard);
        if (!empty($body['description'])) {
            $collection->setDescription((string) $body['description']);
        }
        if (!empty($body['color'])) {
            $collection->setColor((string) $body['color']);
        }
        if (!empty($body['icon'])) {
            $collection->setIcon((string) $body['icon']);
        }
        if (!empty($body['parentId'])) {
            $parent = $this->collections->find((int) $body['parentId']);
            if (null !== $parent && $parent->getDashboard() === $dashboard) {
                $collection->setParent($parent);
            }
        }
        $this->em->persist($collection);
        $this->em->flush();

        return $this->ok($this->serialize($collection), 201);
    }

    #[Route('/api/v1/collections/{id}', name: 'api_collections_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $collection = $this->collections->find($id);
        if (null === $collection) {
            return $this->fail('not found', 404);
        }
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return $this->fail('invalid json');
        }

        if (isset($body['name']) && '' !== trim((string) $body['name'])) {
            $collection->setName(trim((string) $body['name']));
        }
        if (\array_key_exists('description', $body)) {
            $collection->setDescription('' === (string) $body['description'] ? null : (string) $body['description']);
        }
        if (!empty($body['color'])) {
            $collection->setColor((string) $body['color']);
        }
        if (!empty($body['icon'])) {
            $collection->setIcon((string) $body['icon']);
        }
        if (\array_key_exists('parentId', $body)) {
            $parent = empty($body['parentId']) ? null : $this->collections->find((int) $body['parentId']);
            if (null !== $parent && !$this->isValidParent($collection, $parent)) {
                return $this->fail('invalid parent (cycle or other dashboard)');
            }
            $collection->setParent($parent);
        }
        $this->em->flush();

        return $this->ok($this->serialize($collection));
    }

    #[Route('/api/v1/collections/{id}', name: 'api_collections_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $collection = $this->collections->find($id);
        if (null === $collection) {
            return $this->fail('not found', 404);
        }
        foreach ($this->collections->findDescendants($collection) as $descendant) {
            $this->em->remove($descendant);
        }
        $this->em->remove($collection);
        $this->em->flush();

        return $this->ok(['id' => $id]);
    }

    private function isValidParent(Collection $collection, Collection $parent): bool
    {
        if ($parent === $collection || $parent->getDashboard() !== $collection->getDashboard()) {
            return false;
        }
        foreach ($this->collections->findDescendants($collection) as $descendant) {
            if ($descendant === $parent) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Collection $c): array
    {
        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'color' => $c->getColor(),
            'icon' => $c->getIcon(),
            'parentId' => $c->getParent()?->getId(),
            'ownerId' => 1,
            'isPublic' => false,
            'members' => [],
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
