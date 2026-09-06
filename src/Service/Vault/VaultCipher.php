<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\Vault;

final class VaultCipher
{
    /**
     * @return array{passwordHash: string, kdfSalt: string, wrappedKey: string}
     */
    public function createVault(string $password): array
    {
        $passwordHash = sodium_crypto_pwhash_str(
            $password,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
        $kdfSalt = random_bytes(\SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $wrapKey = sodium_crypto_pwhash(
            \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $kdfSalt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
        $dataKey = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrapped = $nonce.sodium_crypto_secretbox($dataKey, $nonce, $wrapKey);
        sodium_memzero($wrapKey);
        sodium_memzero($dataKey);

        return [
            'passwordHash' => $passwordHash,
            'kdfSalt' => base64_encode($kdfSalt),
            'wrappedKey' => base64_encode($wrapped),
        ];
    }

    public function unlock(string $password, Vault $vault): string
    {
        if (!sodium_crypto_pwhash_str_verify($vault->getPasswordHash(), $password)) {
            throw new \RuntimeException('Invalid vault password');
        }
        $salt = base64_decode($vault->getKdfSalt(), true);
        if (false === $salt) {
            throw new \RuntimeException('Corrupt vault salt');
        }
        $wrapKey = sodium_crypto_pwhash(
            \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
        $wrapped = base64_decode($vault->getWrappedKey(), true);
        if (false === $wrapped) {
            throw new \RuntimeException('Corrupt vault wrappedKey');
        }
        $nonce = substr($wrapped, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($wrapped, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_secretbox_open($ciphertext, $nonce, $wrapKey);
        sodium_memzero($wrapKey);
        if (false === $key) {
            throw new \RuntimeException('Vault decrypt failed');
        }

        return $key;
    }

    public function encrypt(string $key, string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public function decrypt(string $key, string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if (false === $raw) {
            throw new \RuntimeException('Corrupt ciphertext');
        }
        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ct, $nonce, $key);
        if (false === $plain) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plain;
    }
}
