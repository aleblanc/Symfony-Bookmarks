<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\Ai\AiServerProbe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AiHealthController extends AbstractController
{
    #[Route('/ai/health', name: 'ai_health', methods: ['GET'])]
    public function health(Request $request, AiServerProbe $probe): JsonResponse
    {
        // ?chat=1 sends a real completion (raw status + body); &big=1 pads the
        // prompt to ~130 lines to reproduce the organize-size request.
        if ($request->query->getBoolean('chat')) {
            return $this->json($probe->chatProbe($request->query->getBoolean('big') ? 130 : 0));
        }

        return $this->json($probe->probe());
    }
}
