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

        // Redirect back where the switch was triggered, but only to a same-site
        // path (never the raw Referer URL) to avoid an open redirect.
        $referer = (string) $request->headers->get('Referer');
        $path = (string) (parse_url($referer, \PHP_URL_PATH) ?: '');
        if ('' !== $path && str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_starts_with($path, '/\\')) {
            $query = parse_url($referer, \PHP_URL_QUERY);

            return $this->redirect(\is_string($query) && '' !== $query ? $path.'?'.$query : $path);
        }

        return $this->redirectToRoute('dashboard');
    }
}
