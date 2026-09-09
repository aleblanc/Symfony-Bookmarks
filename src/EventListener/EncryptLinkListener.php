<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Link;
use App\Service\Vault\VaultCipher;
use App\Service\Vault\VaultSession;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postLoad)]
final class EncryptLinkListener
{
    private const LOCKED_PLACEHOLDER = '[locked]';

    public function __construct(
        private readonly VaultCipher $cipher,
        private readonly VaultSession $session,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Link) {
            return;
        }
        $this->encryptIfVault($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Link) {
            return;
        }
        $this->encryptIfVault($entity);
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Link || !$entity->isEncrypted()) {
            return;
        }
        $vault = $entity->getCollection()->getVault();
        $vaultId = $vault?->getId();
        if (null === $vault || null === $vaultId) {
            return;
        }
        $key = $this->session->get($vaultId);
        if (null === $key) {
            $entity->setUrl(self::LOCKED_PLACEHOLDER);
            $entity->setName(self::LOCKED_PLACEHOLDER);
            $entity->setDescription(null);
            $entity->setTextContent(null);

            return;
        }
        $entity->setUrl($this->cipher->decrypt($key, $entity->getUrl()));
        if (null !== $entity->getName()) {
            $entity->setName($this->cipher->decrypt($key, $entity->getName()));
        }
        if (null !== $entity->getDescription()) {
            $entity->setDescription($this->cipher->decrypt($key, $entity->getDescription()));
        }
        if (null !== $entity->getTextContent()) {
            $entity->setTextContent($this->cipher->decrypt($key, $entity->getTextContent()));
        }
        // Do not flip isEncrypted — decryption is in-memory only, we don't want it re-persisted as plaintext
    }

    private function encryptIfVault(Link $link): void
    {
        $vault = $link->getCollection()->getVault();
        $vaultId = $vault?->getId();
        if (null === $vault || null === $vaultId) {
            return;
        }
        $key = $this->session->get($vaultId);
        if (null === $key) {
            throw new \RuntimeException(\sprintf('Cannot persist link into locked vault #%d', $vaultId));
        }
        $link->setUrl($this->cipher->encrypt($key, $link->getUrl()));
        if (null !== $link->getName()) {
            $link->setName($this->cipher->encrypt($key, $link->getName()));
        }
        if (null !== $link->getDescription()) {
            $link->setDescription($this->cipher->encrypt($key, $link->getDescription()));
        }
        if (null !== $link->getTextContent()) {
            $link->setTextContent($this->cipher->encrypt($key, $link->getTextContent()));
        }
        $link->setEncrypted(true);
    }
}
