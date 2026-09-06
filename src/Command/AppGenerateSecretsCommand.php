<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:generate-secrets', description: 'Generate a random API token to paste into APP_API_TOKEN')]
final class AppGenerateSecretsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = bin2hex(random_bytes(32));
        $output->writeln('# Add this to .env.local:');
        $output->writeln('APP_API_TOKEN='.$token);

        return Command::SUCCESS;
    }
}
