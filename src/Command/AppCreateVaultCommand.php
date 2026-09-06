<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Vault;
use App\Service\Vault\VaultCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-vault', description: 'Create a new vault with the given name and password')]
final class AppCreateVaultCommand extends Command
{
    public function __construct(
        private readonly VaultCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Vault name')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Vault password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $password = (string) $input->getOption('password');
        if ('' === $password) {
            $output->writeln('<error>--password is required</error>');

            return Command::FAILURE;
        }
        $created = $this->cipher->createVault($password);
        $vault = new Vault($name, $created['passwordHash'], $created['kdfSalt'], $created['wrappedKey']);
        $this->em->persist($vault);
        $this->em->flush();
        $output->writeln(sprintf('Vault "%s" created with id %d', $name, (int) $vault->getId()));

        return Command::SUCCESS;
    }
}
