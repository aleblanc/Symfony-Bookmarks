<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\LinkResource;
use App\Entity\Link;
use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Writes links for the API v2: POST (create), PATCH (update), DELETE.
 * Vault collections are refused (their content is encrypted and can only be
 * written with an unlocked session vault, which the API has no notion of).
 *
 * Field validation (URL, lengths, NotBlank) is declared on LinkResource and run
 * by API Platform's ValidateProcessor before this runs; here we only enforce
 * business rules (vault, collection existence).
 *
 * @implements ProcessorInterface<LinkResource, LinkResource|null>
 */
final class LinkProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?LinkResource
    {
        if ($operation instanceof DeleteOperationInterface) {
            $id = isset($uriVariables['id']) ? (int) $uriVariables['id'] : 0;
            $link = $this->links->find($id);
            if (null !== $link) {
                $this->em->remove($link);
                $this->em->flush();
            }

            return null;
        }

        \assert($data instanceof LinkResource);

        $link = null !== $data->id
            ? $this->update($data)   // PATCH: $data is the existing resource + patch
            : $this->create($data);  // POST

        return LinkProvider::toResource($link);
    }

    private function create(LinkResource $data): Link
    {
        $collection = null !== $data->collectionId
            ? $this->collections->find($data->collectionId)
            : $this->collections->findDefaultCollection();
        if (null === $collection) {
            throw new UnprocessableEntityHttpException('no collection available');
        }
        if (null !== $collection->getVault()) {
            throw new UnprocessableEntityHttpException('cannot write to a vault collection via the API');
        }

        $link = new Link($data->url, $collection);
        $link->setName($data->name);
        $link->setDescription($data->description);

        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function update(LinkResource $data): Link
    {
        $link = $this->links->find((int) $data->id) ?? throw new NotFoundHttpException();
        if (null !== $link->getCollection()->getVault()) {
            throw new UnprocessableEntityHttpException('cannot modify a vault collection link via the API');
        }

        $link->setUrl($data->url);
        $link->setName($data->name);
        $link->setDescription($data->description);

        // Move to another collection when collectionId changes (folder move).
        if (null !== $data->collectionId && $data->collectionId !== $link->getCollection()->getId()) {
            $target = $this->collections->find($data->collectionId);
            if (null === $target) {
                throw new UnprocessableEntityHttpException('target collection not found');
            }
            if (null !== $target->getVault()) {
                throw new UnprocessableEntityHttpException('cannot move into a vault collection via the API');
            }
            $link->setCollection($target);
        }

        $this->em->flush();

        return $link;
    }
}
