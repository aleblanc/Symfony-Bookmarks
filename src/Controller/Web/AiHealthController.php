<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\Ai\AiServerProbe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AiHealthController extends AbstractController
{
    #[Route('/ai/health', name: 'ai_health', methods: ['GET'])]
    public function health(AiServerProbe $probe): JsonResponse
    {
        return $this->json($probe->probe());
    }
}
