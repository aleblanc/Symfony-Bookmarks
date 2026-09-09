<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
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
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $dashboard = $this->current->get();

        $byCollection = [];
        foreach ($this->collections->findRootsForDashboard($dashboard) as $collection) {
            $recent = $this->links->findRecentForCollection($collection, 10);
            if ([] !== $recent) {
                $byCollection[] = ['collection' => $collection, 'links' => $recent];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'dashboard' => $dashboard,
            'clicked' => $this->links->findRecentlyClicked($dashboard, 8),
            'most_clicked' => $this->links->findMostClicked($dashboard, 8),
            'added' => $this->links->findForDashboard($dashboard, 8, null, 'DESC'),
            'by_collection' => $byCollection,
        ]);
    }
}
