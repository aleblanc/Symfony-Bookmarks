<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
use App\Repository\TagRepository;
use App\Service\CurrentDashboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly CurrentDashboard $current,
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
        private readonly TagRepository $tags,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $dashboard = $this->current->get();

        return $this->render('dashboard/index.html.twig', [
            'dashboard' => $dashboard,
            'recent_links' => $this->links->findForDashboard($dashboard, 12),
            'collections' => $this->collections->findForDashboard($dashboard),
            'tags' => $this->tags->findForDashboard($dashboard),
        ]);
    }
}
