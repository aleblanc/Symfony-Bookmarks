<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Collection;
use App\Entity\Link;
use App\Repository\CollectionRepository;
use App\Repository\DashboardRepository;
use App\Repository\LinkRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Full, un-paginated export of the whole bookmark tree — dashboards holding
 * their nested collections, each collection holding its links. Meant to make
 * client-side import/sync (the Firefox extension) trivial: one request, no
 * cursor walking.
 */
final class TreeController extends AbstractApiController
{
    public function __construct(
        private readonly DashboardRepository $dashboards,
        private readonly CollectionRepository $collections,
        private readonly LinkRepository $links,
    ) {
    }

    #[Route('/api/v1/tree', name: 'api_tree', methods: ['GET'])]
    public function tree(): JsonResponse
    {
        // Links grouped by their collection id.
        $linksByCollection = [];
        foreach ($this->links->findAllForExport() as $link) {
            $linksByCollection[(int) $link->getCollection()->getId()][] = $this->serializeLink($link);
        }

        // Build one node per collection, then wire parent/child links.
        // "À trier" (skipProcessing) collections — and their whole subtree — are
        // excluded from the export: they hold links the user hasn't triaged yet,
        // which should not be mirrored into Firefox.
        $collections = $this->collections->findAll();
        $nodes = [];
        foreach ($collections as $collection) {
            if ($this->isExcluded($collection)) {
                continue;
            }
            $id = (int) $collection->getId();
            $nodes[$id] = $this->serializeCollection($collection) + [
                'links' => $linksByCollection[$id] ?? [],
                'children' => [],
            ];
        }

        // Roots per dashboard; children attached to their parent when present.
        $rootsByDashboard = [];
        foreach ($collections as $collection) {
            if ($this->isExcluded($collection)) {
                continue;
            }
            $id = (int) $collection->getId();
            $parentId = $collection->getParent()?->getId();
            if (null !== $parentId && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$nodes[$id];
            } else {
                $rootsByDashboard[(int) $collection->getDashboard()->getId()][] = &$nodes[$id];
            }
        }

        $tree = [];
        foreach ($this->dashboards->findAllOrdered() as $dashboard) {
            $did = (int) $dashboard->getId();
            $tree[] = [
                'id' => $did,
                'name' => $dashboard->getName(),
                'color' => $dashboard->getColor(),
                'collections' => $rootsByDashboard[$did] ?? [],
            ];
        }

        return $this->ok(['dashboards' => $tree]);
    }

    /** A collection is excluded if it, or any ancestor, is flagged "à trier". */
    private function isExcluded(Collection $c): bool
    {
        for ($cur = $c; null !== $cur; $cur = $cur->getParent()) {
            if ($cur->isSkipProcessing()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCollection(Collection $c): array
    {
        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'color' => $c->getColor(),
            'icon' => $c->getIcon(),
            'parentId' => $c->getParent()?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLink(Link $link): array
    {
        $tags = [];
        foreach ($link->getTags() as $tag) {
            $tags[] = $tag->getName();
        }

        return [
            'id' => $link->getId(),
            'name' => $link->getName(),
            'url' => $link->getUrl(),
            'description' => $link->getDescription(),
            'tags' => $tags,
            'createdAt' => $link->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
