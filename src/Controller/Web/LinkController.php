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

final class LinkController extends AbstractController
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
        private readonly TagRepository $tags,
        private readonly CurrentDashboard $current,
        private readonly LinkSearch $search,
        private readonly EntityManagerInterface $em,
        private readonly ArchiveAssetRepository $assets,
    ) {
    }

    #[Route('/links', name: 'links_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $dashboard = $this->current->get();
        $collectionId = $request->query->get('collection');
        $tagId = $request->query->get('tag');
        $q = trim((string) $request->query->get('q', ''));

        if ('' !== $q) {
            $ids = $this->search->ftsIds($q);
            $links = $this->links->findByIds($ids);
        } elseif (null !== $collectionId) {
            $collection = $this->collections->find((int) $collectionId);
            $links = null === $collection ? [] : $this->links->findForCollection($collection);
        } elseif (null !== $tagId) {
            $tag = $this->tags->find((int) $tagId);
            $links = null === $tag ? [] : $this->links->findForTag($tag);
        } else {
            $links = $this->links->findForDashboard($dashboard, 50, null, 'ASC');
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
        $collections = $this->collections->findForDashboard($dashboard);
        if ($request->isMethod('POST')) {
            $url = trim((string) $request->request->get('url', ''));
            $collectionId = (int) $request->request->get('collection', 0);
            $collection = 0 === $collectionId ? null : $this->collections->find($collectionId);
            if ('' !== $url && null !== $collection && false !== filter_var($url, \FILTER_VALIDATE_URL)) {
                $link = new Link($url, $collection);
                $name = trim((string) $request->request->get('name', ''));
                if ('' !== $name) {
                    $link->setName($name);
                }
                $this->em->persist($link);
                $this->em->flush();

                return $this->redirectToRoute('links_index');
            }
        }

        return $this->render('links/new.html.twig', [
            'dashboard' => $dashboard,
            'collections' => $collections,
            'preselect' => null !== $request->query->get('collection') ? (int) $request->query->get('collection') : null,
        ]);
    }

    #[Route('/links/{id}/edit', name: 'links_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $dashboard = $this->current->get();
        $collections = $this->collections->findForDashboard($dashboard);

        if ($request->isMethod('POST')) {
            $url = trim((string) $request->request->get('url', ''));
            $collectionId = (int) $request->request->get('collection', 0);
            $collection = 0 === $collectionId ? null : $this->collections->find($collectionId);
            if ('' !== $url && null !== $collection && false !== filter_var($url, \FILTER_VALIDATE_URL)) {
                $link->setUrl($url);
                $link->setCollection($collection);
                $name = trim((string) $request->request->get('name', ''));
                $link->setName('' !== $name ? $name : null);

                // Editing "un-deadifies" the link: clear the health verdict so it drops
                // out of the dead list and is re-checked fresh on the next run.
                $link->setHealthStatus(Link::HEALTH_UNKNOWN);
                $link->setHttpStatus(null);
                $link->setHealthCheckedAt(null);

                $this->em->flush();

                return $this->redirectToRoute('links_show', ['id' => $link->getId()]);
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
        ]);
    }

    #[Route('/links/{id}', name: 'links_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();

        return $this->render('links/show.html.twig', [
            'link' => $link,
            'assets' => $this->assets->findForLink($link),
        ]);
    }

    #[Route('/links/{id}/delete', name: 'links_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $this->em->remove($link);
        $this->em->flush();

        // Stay on the page the deletion was triggered from (dashboard, a collection,
        // a filtered list…). The form posts the current URL as return_to; only a
        // same-site path is honoured, to avoid an open redirect.
        $returnTo = (string) $request->request->get('return_to', '');
        if (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
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

    #[Route('/links/{id}/rearchive', name: 'links_rearchive', methods: ['POST'])]
    public function reArchive(int $id): RedirectResponse
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $link->setStatus(Link::STATUS_PENDING);
        $link->setAiStatus(Link::AI_PENDING);
        $link->setLastError(null);
        $this->em->flush();

        return $this->redirectToRoute('links_show', ['id' => $id]);
    }
}
