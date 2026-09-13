<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\CollectionResource;
use App\Entity\Collection;
use App\Repository\CollectionRepository;
use App\Repository\DashboardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Writes collections for the API v2: POST / PATCH / DELETE. Vault collections
 * are hidden (provider returns null → 404 on item ops).
 *
 * @implements ProcessorInterface<CollectionResource, CollectionResource|null>
 */
final class CollectionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollectionRepository $collections,
        private readonly DashboardRepository $dashboards,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?CollectionResource
    {
        if ($operation instanceof DeleteOperationInterface) {
            $id = isset($uriVariables['id']) ? (int) $uriVariables['id'] : 0;
            $collection = $this->collections->find($id);
            if (null !== $collection) {
                foreach ($this->collections->findDescendants($collection) as $descendant) {
                    $this->em->remove($descendant);
                }
                $this->em->remove($collection);
                $this->em->flush();
            }

            return null;
        }

        \assert($data instanceof CollectionResource);

        $collection = null !== $data->id ? $this->update($data) : $this->create($data);

        return CollectionProvider::toResource($collection);
    }

    private function create(CollectionResource $data): Collection
    {
        $dashboard = null !== $data->dashboardId
            ? $this->dashboards->find($data->dashboardId)
            : $this->dashboards->find(1);
        if (null === $dashboard) {
            throw new UnprocessableEntityHttpException('no dashboard available');
        }

        $collection = new Collection(trim($data->name), $dashboard);
        $collection->setDescription($data->description);
        if (null !== $data->color) {
            $collection->setColor($data->color);
        }
        if (null !== $data->icon) {
            $collection->setIcon($data->icon);
        }
        $collection->setSkipProcessing($data->skipProcessing);
        $this->applyParent($collection, $data->parentId);

        $this->em->persist($collection);
        $this->em->flush();

        return $collection;
    }

    private function update(CollectionResource $data): Collection
    {
        $collection = $this->collections->find((int) $data->id) ?? throw new NotFoundHttpException();
        if (null !== $collection->getVault()) {
            throw new UnprocessableEntityHttpException('cannot modify a vault collection via the API');
        }

        $collection->setName(trim($data->name));
        $collection->setDescription($data->description);
        if (null !== $data->color) {
            $collection->setColor($data->color);
        }
        if (null !== $data->icon) {
            $collection->setIcon($data->icon);
        }
        $collection->setSkipProcessing($data->skipProcessing);
        $this->applyParent($collection, $data->parentId);

        $this->em->flush();

        return $collection;
    }

    /** Set (or clear) the parent, rejecting cycles and cross-dashboard moves. */
    private function applyParent(Collection $collection, ?int $parentId): void
    {
        if (null === $parentId) {
            $collection->setParent(null);

            return;
        }
        $parent = $this->collections->find($parentId);
        if (null === $parent) {
            throw new UnprocessableEntityHttpException('parent not found');
        }
        if ($parent === $collection || $parent->getDashboard() !== $collection->getDashboard()) {
            throw new UnprocessableEntityHttpException('invalid parent (self or other dashboard)');
        }
        // Cycle check only for an existing collection: a not-yet-persisted one has
        // no id (Doctrine can't bind it as a query param) and has no descendants.
        if (null !== $collection->getId()) {
            foreach ($this->collections->findDescendants($collection) as $descendant) {
                if ($descendant === $parent) {
                    throw new UnprocessableEntityHttpException('invalid parent (cycle)');
                }
            }
        }
        $collection->setParent($parent);
    }
}
