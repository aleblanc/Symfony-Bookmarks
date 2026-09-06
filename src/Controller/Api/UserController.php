<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractApiController
{
    #[Route('/api/v1/users/me', name: 'api_users_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->ok([
            'id' => 1,
            'username' => 'self',
            'email' => null,
            'name' => 'Self',
            'subscription' => null,
        ]);
    }
}
