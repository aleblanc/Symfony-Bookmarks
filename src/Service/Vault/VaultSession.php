<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\HttpFoundation\RequestStack;

final class VaultSession
{
    private const TTL_SECONDS = 900;

    public function __construct(private readonly RequestStack $stack)
    {
    }

    public function store(int $vaultId, string $key): void
    {
        $this->stack->getSession()->set("vault_{$vaultId}", [
            'key' => base64_encode($key),
            'exp' => time() + self::TTL_SECONDS,
        ]);
    }

    public function get(int $vaultId): ?string
    {
        try {
            $session = $this->stack->getSession();
        } catch (\Throwable) {
            return null;
        }
        /** @var array{key: string, exp: int}|null $entry */
        $entry = $session->get("vault_{$vaultId}");
        if (null === $entry || $entry['exp'] < time()) {
            $this->forget($vaultId);

            return null;
        }
        $raw = base64_decode($entry['key'], true);

        return false === $raw ? null : $raw;
    }

    public function forget(int $vaultId): void
    {
        try {
            $this->stack->getSession()->remove("vault_{$vaultId}");
        } catch (\Throwable) {
            // no session, nothing to forget
        }
    }
}
