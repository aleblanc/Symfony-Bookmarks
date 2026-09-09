<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
use App\Service\CurrentDashboard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CollectionController extends AbstractController
{
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly LinkRepository $links,
        private readonly CurrentDashboard $current,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/collections', name: 'collections_index', methods: ['GET'])]
    public function index(): Response
    {
        $dashboard = $this->current->get();

        return $this->render('collections/index.html.twig', [
            'dashboard' => $dashboard,
            'tree' => $this->collections->findTreeForDashboard($dashboard),
        ]);
    }

    #[Route('/collections/{id}', name: 'collections_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();

        // Merge targets: every other folder of the dashboard, minus this one and its
        // descendants (merging into a descendant would delete it via the cascade).
        $forbidden = [$collection->getId()];
        foreach ($this->collections->findDescendants($collection) as $descendant) {
            $forbidden[] = $descendant->getId();
        }
        $mergeTargets = array_values(array_filter(
            $this->collections->findForDashboard($collection->getDashboard()),
            static fn (Collection $c): bool => !\in_array($c->getId(), $forbidden, true),
        ));

        return $this->render('collections/show.html.twig', [
            'collection' => $collection,
            'children' => $this->collections->findChildren($collection),
            'links' => $this->links->findForCollection($collection),
            'merge_targets' => $mergeTargets,
        ]);
    }

    #[Route('/collections/new', name: 'collections_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dashboard = $this->current->get();

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            if ('' !== $name) {
                $collection = new Collection($name, $dashboard);
                $description = trim((string) $request->request->get('description', ''));
                if ('' !== $description) {
                    $collection->setDescription($description);
                }
                $color = trim((string) $request->request->get('color', ''));
                if ('' !== $color) {
                    $collection->setColor($color);
                }
                $icon = trim((string) $request->request->get('icon', ''));
                if ('' !== $icon) {
                    $collection->setIcon($icon);
                }
                $parent = $this->resolveParent($request->request->get('parent'), $dashboard);
                $collection->setParent($parent);
                $collection->setSkipProcessing($request->request->getBoolean('skip_processing'));
                $this->em->persist($collection);
                $this->em->flush();

                return $this->redirectToRoute(
                    null !== $parent ? 'collections_show' : 'collections_index',
                    null !== $parent ? ['id' => $parent->getId()] : [],
                );
            }
        }

        return $this->render('collections/new.html.twig', [
            'dashboard' => $dashboard,
            'collections' => $this->collections->findForDashboard($dashboard),
            'parent' => $this->resolveParent($request->query->get('parent'), $dashboard),
        ]);
    }

    #[Route('/collections/{id}/edit', name: 'collections_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $dashboard = $collection->getDashboard();

        // A folder cannot be moved under itself or one of its descendants.
        $forbidden = [$collection->getId()];
        foreach ($this->collections->findDescendants($collection) as $descendant) {
            $forbidden[] = $descendant->getId();
        }
        $parentChoices = array_values(array_filter(
            $this->collections->findForDashboard($dashboard),
            static fn (Collection $c): bool => !\in_array($c->getId(), $forbidden, true),
        ));

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            if ('' !== $name) {
                $collection->setName($name);
                $collection->setDescription(trim((string) $request->request->get('description', '')) ?: null);
                $color = trim((string) $request->request->get('color', ''));
                if ('' !== $color) {
                    $collection->setColor($color);
                }
                $icon = trim((string) $request->request->get('icon', ''));
                if ('' !== $icon) {
                    $collection->setIcon($icon);
                }
                $parent = $this->resolveParent($request->request->get('parent'), $dashboard);
                // Ignore an illegal move (into self/descendant): keep the current parent.
                if (null !== $parent && \in_array($parent->getId(), $forbidden, true)) {
                    $parent = $collection->getParent();
                }
                $collection->setParent($parent);
                $collection->setSkipProcessing($request->request->getBoolean('skip_processing'));
                $this->em->flush();

                return $this->redirectToRoute('collections_show', ['id' => $collection->getId()]);
            }
        }

        return $this->render('collections/edit.html.twig', [
            'collection' => $collection,
            'collections' => $parentChoices,
        ]);
    }

    #[Route('/collections/{id}/move', name: 'collections_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(int $id, Request $request): RedirectResponse
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $direction = (string) $request->request->get('direction');

        // Reorder within the sibling group (same parent). Take the ordered list, move
        // this folder to its target slot, then renumber 0..n. Handles up/down (±1) and
        // top/bottom (first/last). Legacy rows all at 0 get normalised in the process.
        $siblings = $this->collections->findSiblings($collection);
        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling->getId() === $collection->getId()) {
                $index = $i;
                break;
            }
        }
        if (null !== $index) {
            $last = \count($siblings) - 1;
            $target = match ($direction) {
                'up' => $index - 1,
                'down' => $index + 1,
                'top' => 0,
                'bottom' => $last,
                'middle' => intdiv($last, 2),
                default => $index,
            };
            $target = max(0, min($last, $target));
            if ($target !== $index) {
                $moved = array_splice($siblings, $index, 1);
                array_splice($siblings, $target, 0, $moved);
            }
        }
        foreach ($siblings as $i => $sibling) {
            $sibling->setPosition($i);
        }
        $this->em->flush();

        $parent = $collection->getParent();

        return null !== $parent
            ? $this->redirectToRoute('collections_show', ['id' => $parent->getId()])
            : $this->redirectToRoute('collections_index');
    }

    #[Route('/collections/{id}/merge', name: 'collections_merge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function merge(int $id, Request $request): RedirectResponse
    {
        $source = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $targetId = (int) $request->request->get('target', 0);
        $target = $targetId > 0 ? $this->collections->find($targetId) : null;

        // Guard: a real, different folder in the same dashboard, not a descendant of
        // the source (that would be deleted by the cascade), and same vault setting
        // (moving links across differing encryption would corrupt them).
        $forbidden = [$source->getId()];
        foreach ($this->collections->findDescendants($source) as $descendant) {
            $forbidden[] = $descendant->getId();
        }
        $sameVault = $source->getVault()?->getId() === $target?->getVault()?->getId();
        if (null === $target
            || $target->getDashboard() !== $source->getDashboard()
            || \in_array($target->getId(), $forbidden, true)
            || !$sameVault
        ) {
            return $this->redirectToRoute('collections_show', ['id' => $source->getId()]);
        }

        // Move the source's direct links, then reparent its sub-folders under the target.
        $this->links->moveAllToCollection($source, $target);
        foreach ($this->collections->findChildren($source) as $child) {
            $child->setParent($target);
        }
        $this->em->flush();

        // Renumber the target's children (positions may now collide) and drop the source.
        foreach ($this->collections->findChildren($target) as $i => $child) {
            $child->setPosition($i);
        }
        $this->em->remove($source);
        $this->em->flush();

        return $this->redirectToRoute('collections_show', ['id' => $target->getId()]);
    }

    #[Route('/collections/{id}/delete', name: 'collections_delete', methods: ['POST'])]
    public function delete(int $id): RedirectResponse
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $parent = $collection->getParent();
        // parent_id has no DB-level FK (SQLite ALTER limit), so remove descendants
        // ourselves; each collection's links cascade via their enforced FK.
        $this->deleteRecursively($collection);
        $this->em->flush();

        return null !== $parent
            ? $this->redirectToRoute('collections_show', ['id' => $parent->getId()])
            : $this->redirectToRoute('collections_index');
    }

    private function deleteRecursively(Collection $collection): void
    {
        foreach ($this->collections->findChildren($collection) as $child) {
            $this->deleteRecursively($child);
        }
        $this->em->remove($collection);
    }

    private function resolveParent(mixed $rawId, Dashboard $dashboard): ?Collection
    {
        if (null === $rawId || '' === $rawId) {
            return null;
        }
        $parent = $this->collections->find((int) $rawId);

        return null !== $parent && $parent->getDashboard() === $dashboard ? $parent : null;
    }
}
