<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\VaultRepository;
use App\Service\Vault\VaultCipher;
use App\Service\Vault\VaultSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VaultController extends AbstractController
{
    public function __construct(
        private readonly VaultRepository $vaults,
        private readonly VaultCipher $cipher,
        private readonly VaultSession $session,
    ) {
    }

    #[Route('/vault/{id}/unlock', name: 'vault_unlock', methods: ['GET', 'POST'])]
    public function unlock(int $id, Request $request): Response
    {
        $vault = $this->vaults->find($id) ?? throw $this->createNotFoundException();
        $error = null;
        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            try {
                $key = $this->cipher->unlock($password, $vault);
                $this->session->store((int) $vault->getId(), $key);

                return $this->redirectToRoute('dashboard');
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('vault/unlock.html.twig', ['vault' => $vault, 'error' => $error]);
    }

    #[Route('/vault/{id}/lock', name: 'vault_lock', methods: ['POST'])]
    public function lock(int $id): RedirectResponse
    {
        $this->session->forget($id);

        return $this->redirectToRoute('dashboard');
    }
}
