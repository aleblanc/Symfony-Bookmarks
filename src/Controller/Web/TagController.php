<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\TagRepository;
use App\Service\CurrentDashboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TagController extends AbstractController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly CurrentDashboard $current,
    ) {
    }

    #[Route('/tags', name: 'tags_index', methods: ['GET'])]
    public function index(): Response
    {
        $dashboard = $this->current->get();

        return $this->render('tags/index.html.twig', [
            'dashboard' => $dashboard,
            'tags' => $this->tags->findForDashboardWithCounts($dashboard),
        ]);
    }
}
