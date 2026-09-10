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
    /** Favorite links listed first on the dashboard. */
    private const FAVORITES_LIMIT = 12;

    /** Links shown in each dashboard highlight strip (recent / most-clicked / newest). */
    private const STRIP_LIMIT = 8;

    /** Links previewed under each folder on the dashboard. */
    private const PER_FOLDER_LIMIT = 10;

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
            $recent = $this->links->findRecentForCollection($collection, self::PER_FOLDER_LIMIT);
            if ([] !== $recent) {
                $byCollection[] = ['collection' => $collection, 'links' => $recent];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'dashboard' => $dashboard,
            'favorites' => $this->links->findFavoritesForDashboard($dashboard, self::FAVORITES_LIMIT),
            'clicked' => $this->links->findRecentlyClicked($dashboard, self::STRIP_LIMIT),
            'most_clicked' => $this->links->findMostClicked($dashboard, self::STRIP_LIMIT),
            'added' => $this->links->findForDashboard($dashboard, self::STRIP_LIMIT, null, 'DESC'),
            'by_collection' => $byCollection,
        ]);
    }
}
