<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Link;
use App\Repository\LinkRepository;
use App\Service\Health\LinkHealthChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:check-links',
    description: 'HTTP-check links and flag dead ones (404/410). Least-recently-checked first.',
)]
final class AppCheckLinksCommand extends Command
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly LinkHealthChecker $checker,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max links to check this run (0 = all)', '100');
        $this->addOption('recheck-after', null, InputOption::VALUE_REQUIRED, 'Skip links already checked within the last N days (0 = always recheck)', '7');
        $this->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Also check links in "to sort" (ignored) folders');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recheckAfterDays = (int) $input->getOption('recheck-after');
        $notCheckedSince = $recheckAfterDays > 0
            ? new \DateTimeImmutable(\sprintf('-%d days', $recheckAfterDays))
            : null;

        $links = $this->links->findForHealthCheck(
            (int) $input->getOption('limit'),
            $notCheckedSince,
            (bool) $input->getOption('include-ignored'),
        );

        if ([] === $links) {
            $output->writeln($recheckAfterDays > 0
                ? \sprintf('Nothing to check — all links were verified within the last %d day(s).', $recheckAfterDays)
                : 'No links to check.');

            return Command::SUCCESS;
        }

        $checked = 0;
        $dead = 0;
        foreach ($links as $link) {
            $result = $this->checker->check($link->getUrl());
            $link->setHealthStatus($result['status']);
            $link->setHttpStatus($result['httpStatus']);
            $link->setHealthCheckedAt(new \DateTimeImmutable());
            $this->em->flush();

            ++$checked;
            if (Link::HEALTH_DEAD === $result['status']) {
                ++$dead;
            }
            $output->writeln(\sprintf(
                '#%d [%s] %s %s',
                (int) $link->getId(),
                $result['status'],
                $result['httpStatus'] ?? '---',
                $link->getUrl(),
            ));
        }

        $output->writeln(\sprintf('%d checked, %d dead', $checked, $dead));
        // Make the default caps obvious so "it didn't check everything" isn't a surprise.
        $limit = (int) $input->getOption('limit');
        if ($limit > 0 && $checked >= $limit) {
            $output->writeln(\sprintf(
                'Reached --limit=%d; more may remain. Re-run, or use --limit=0 --recheck-after=0%s for a full sweep.',
                $limit,
                $input->getOption('include-ignored') ? '' : ' --include-ignored',
            ));
        }
        $this->logger->info('link health check run', ['checked' => $checked, 'dead' => $dead]);

        return Command::SUCCESS;
    }
}
