<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\DashboardRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardsController extends AbstractApiController
{
    public function __construct(
        private readonly DashboardRepository $dashboards,
    ) {
    }

    /** Lightweight list for the extension's dashboard picker (Perso / Pro / …). */
    #[Route('/api/v1/dashboards', name: 'api_dashboards_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = [];
        foreach ($this->dashboards->findAllOrdered() as $dashboard) {
            $items[] = [
                'id' => $dashboard->getId(),
                'name' => $dashboard->getName(),
                'color' => $dashboard->getColor(),
            ];
        }

        return $this->ok($items);
    }
}
