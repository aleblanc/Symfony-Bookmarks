<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Link;
use App\Repository\ArchiveAssetRepository;
use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
use App\Repository\TagRepository;
use App\Service\CurrentDashboard;
use App\Service\Search\LinkSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LinkController extends AbstractController
{
    /** Links shown per page on the main list. */
    private const LIST_LIMIT = 50;

    public function __construct(
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
        private readonly TagRepository $tags,
        private readonly CurrentDashboard $current,
        private readonly LinkSearch $search,
        private readonly EntityManagerInterface $em,
        private readonly ArchiveAssetRepository $assets,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/links', name: 'links_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $dashboard = $this->current->get();
        $collectionId = $request->query->has('collection') ? $request->query->getInt('collection') : null;
        $tagId = $request->query->has('tag') ? $request->query->getInt('tag') : null;
        $q = trim($request->query->getString('q'));

        if ('' !== $q) {
            $ids = $this->search->ftsIds($q);
            $links = $this->links->findByIds($ids);
        } elseif (null !== $collectionId) {
            $collection = $this->collections->find($collectionId);
            $links = null === $collection ? [] : $this->links->findForCollection($collection);
        } elseif (null !== $tagId) {
            $tag = $this->tags->find($tagId);
            $links = null === $tag ? [] : $this->links->findForTag($tag);
        } else {
            $links = $this->links->findForDashboard($dashboard, self::LIST_LIMIT, null, 'ASC');
        }

        return $this->render('links/index.html.twig', [
            'dashboard' => $dashboard,
            'links' => $links,
            'collections' => $this->collections->findForDashboard($dashboard),
            'query' => $q,
        ]);
    }

    #[Route('/links/new', name: 'links_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dashboard = $this->current->get();
        $collections = $this->collections->findForDashboardTreeOrder($dashboard);
        if ($request->isMethod('POST')) {
            $collectionId = $request->request->getInt('collection');
            $collection = 0 === $collectionId ? null : $this->collections->find($collectionId);
            if (null === $collection) {
                $this->addFlash('error', 'link.need_collection');
            } else {
                $link = new Link(trim($request->request->getString('url')), $collection);
                $name = trim($request->request->getString('name'));
                if ('' !== $name) {
                    $link->setName($name);
                }
                if ($this->isValid($link)) {
                    $this->em->persist($link);
                    $this->em->flush();

                    // Land back in the folder the link was added to, not the global list.
                    return $this->redirectToRoute('collections_show', ['id' => $collection->getId()]);
                }
            }
        }

        return $this->render('links/new.html.twig', [
            'dashboard' => $dashboard,
            'collections' => $collections,
            'preselect' => $request->query->has('collection') ? $request->query->getInt('collection') : null,
        ]);
    }

    #[Route('/links/{id}/edit', name: 'links_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Link $link, Request $request): Response
    {
        $dashboard = $this->current->get();
        $collections = $this->collections->findForDashboardTreeOrder($dashboard);

        if ($request->isMethod('POST')) {
            $collectionId = $request->request->getInt('collection');
            $collection = 0 === $collectionId ? null : $this->collections->find($collectionId);
            if (null === $collection) {
                $this->addFlash('error', 'link.need_collection');
            } else {
                $link->setUrl(trim($request->request->getString('url')));
                $link->setCollection($collection);
                $name = trim($request->request->getString('name'));
                $link->setName('' !== $name ? $name : null);

                if ($this->isValid($link)) {
                    // Editing "un-deadifies" the link: clear the health verdict so it drops
                    // out of the dead list and is re-checked fresh on the next run.
                    $link->setHealthStatus(Link::HEALTH_UNKNOWN);
                    $link->setHttpStatus(null);
                    $link->setHealthCheckedAt(null);

                    $this->em->flush();

                    return $this->redirectToRoute('links_show', ['id' => $link->getId()]);
                }
            }
        }

        return $this->render('links/edit.html.twig', [
            'link' => $link,
            'collections' => $collections,
        ]);
    }

    // Declared before /links/{id} so the static path wins ({id} has no digit guard).
    #[Route('/links/dead', name: 'links_dead', methods: ['GET'])]
    public function dead(): Response
    {
        $dashboard = $this->current->get();

        return $this->render('links/dead.html.twig', [
            'dashboard' => $dashboard,
            'links' => $this->links->findDeadForDashboard($dashboard),
            'unreachable' => $this->links->findUnreachableForDashboard($dashboard),
        ]);
    }

    #[Route('/links/{id}', name: 'links_show', methods: ['GET'])]
    public function show(Link $link): Response
    {
        return $this->render('links/show.html.twig', [
            'link' => $link,
            'assets' => $this->assets->findForLink($link),
        ]);
    }

    #[Route('/links/{id}/delete', name: 'links_delete', methods: ['POST'])]
    public function delete(Link $link, Request $request): Response
    {
        $this->em->remove($link);
        $this->em->flush();

        // AJAX delete (dead-links page): no redirect — the caller just removes the card.
        if ($request->isXmlHttpRequest()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Stay on the page the deletion was triggered from (dashboard, a collection,
        // a filtered list…). The form posts the current URL as return_to; only a
        // same-site path is honoured, to avoid an open redirect.
        $returnTo = $request->request->getString('return_to');
        // Same-site path only. Reject "//host" and "/\host" (browsers normalise
        // the backslash to a slash → protocol-relative open redirect).
        if (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_starts_with($returnTo, '/\\')) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('links_index');
    }

    #[Route('/links/{id}/click', name: 'links_click', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function click(int $id): Response
    {
        $this->links->registerClick($id);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/links/{id}/favorite', name: 'links_favorite', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleFavorite(Link $link, Request $request): Response
    {
        $link->setFavorite(!$link->isFavorite());
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Return to the page the toggle was triggered from (same-site path only).
        $returnTo = $request->request->getString('return_to');
        if (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_starts_with($returnTo, '/\\')) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('links_index');
    }

    #[Route('/links/{id}/rearchive', name: 'links_rearchive', methods: ['POST'])]
    public function reArchive(Link $link): RedirectResponse
    {
        $link->setStatus(Link::STATUS_PENDING);
        $link->setAiStatus(Link::AI_PENDING);
        $link->setLastError(null);
        $this->em->flush();

        return $this->redirectToRoute('links_show', ['id' => $link->getId()]);
    }

    /** Validate the entity against its #[Assert] constraints; flash each violation. */
    private function isValid(Link $link): bool
    {
        $errors = $this->validator->validate($link);
        foreach ($errors as $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return 0 === \count($errors);
    }
}
