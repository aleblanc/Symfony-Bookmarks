<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class AbstractApiController extends AbstractController
{
    protected function ok(mixed $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse(['response' => $payload], $status);
    }

    protected function fail(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['response' => null, 'message' => $message], $status);
    }
}
