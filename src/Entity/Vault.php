<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VaultRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VaultRepository::class)]
#[ORM\Table(name: 'vaults')]
class Vault
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 64)]
    private string $kdfSalt;

    #[ORM\Column(length: 255)]
    private string $wrappedKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $passwordHash, string $kdfSalt, string $wrappedKey)
    {
        $this->name = $name;
        $this->passwordHash = $passwordHash;
        $this->kdfSalt = $kdfSalt;
        $this->wrappedKey = $wrappedKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getKdfSalt(): string
    {
        return $this->kdfSalt;
    }

    public function getWrappedKey(): string
    {
        return $this->wrappedKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
