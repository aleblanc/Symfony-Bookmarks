<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\CurrentDashboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardSwitchController extends AbstractController
{
    public function __construct(private readonly CurrentDashboard $current)
    {
    }

    #[Route('/dashboard/switch/{id}', name: 'dashboard_switch', methods: ['POST'])]
    public function switch(int $id, Request $request): RedirectResponse
    {
        $this->current->switch($id);
        $referer = (string) $request->headers->get('Referer');

        return $this->redirect('' !== $referer ? $referer : $this->generateUrl('dashboard'));
    }
}
