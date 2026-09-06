<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Collection;
use App\Repository\CollectionRepository;
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
            'collections' => $this->collections->findForDashboard($dashboard),
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
                $this->em->persist($collection);
                $this->em->flush();

                return $this->redirectToRoute('collections_index');
            }
        }

        return $this->render('collections/new.html.twig', ['dashboard' => $dashboard]);
    }

    #[Route('/collections/{id}/delete', name: 'collections_delete', methods: ['POST'])]
    public function delete(int $id): RedirectResponse
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $this->em->remove($collection);
        $this->em->flush();

        return $this->redirectToRoute('collections_index');
    }
}
