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
        $this->em->persist($collection);
        $this->em->flush();

        return $this->ok($this->serialize($collection), 201);
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
            'ownerId' => 1,
            'isPublic' => false,
            'members' => [],
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
