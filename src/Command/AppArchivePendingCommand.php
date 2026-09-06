<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LinkRepository;
use App\Service\Archiver\ArchiveRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:archive-pending', description: 'Archive links whose status is pending')]
final class AppArchivePendingCommand extends Command
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly ArchiveRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max links per run', '20');
        $this->addOption('rearchive', null, InputOption::VALUE_NONE, 'Requeue already-archived and failed links first, then re-fetch them (regenerates previews)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        if ($input->getOption('rearchive')) {
            $requeued = $this->links->requeueAllForArchive();
            $output->writeln(\sprintf('%d link(s) requeued for re-archiving', $requeued));
        }
        $links = $this->links->findPendingArchive($limit);
        foreach ($links as $link) {
            $output->writeln(\sprintf('archiving #%d %s', (int) $link->getId(), $link->getUrl()));
            $this->runner->run($link);
        }
        $output->writeln(\sprintf('%d links processed', \count($links)));

        return Command::SUCCESS;
    }
}
