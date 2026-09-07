<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Link;
use App\Repository\LinkRepository;
use App\Service\Ai\AutoSummarizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai-summarize-pending', description: 'Summarize archived links whose summary_status is pending via the configured LLM agent')]
#[AsCronTask('*/15 * * * *', description: 'AI-summarize pending links')]
final class AppAiSummarizePendingCommand extends Command
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly AutoSummarizer $summarizer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $aiLogger,
        private readonly bool $enabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max links per run', '20');
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Requeue links whose summarization previously failed, then process them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->enabled) {
            $output->writeln('AI disabled (APP_AI_ENABLED=false)');

            return Command::SUCCESS;
        }
        if ($input->getOption('retry-failed')) {
            $reset = $this->links->resetFailedSummaryStatus();
            $output->writeln(\sprintf('%d failed link(s) requeued', $reset));
        }

        $links = $this->links->findPendingSummary((int) $input->getOption('limit'));
        foreach ($links as $link) {
            $text = $link->getTextContent() ?? '';
            if ('' === trim($text)) {
                $link->setSummaryStatus(Link::SUMMARY_SKIP);
                $this->em->flush();
                continue;
            }
            try {
                $summary = $this->summarizer->summarize($link->getName() ?? '', $text);
                if ('' === $summary) {
                    $link->setSummaryStatus(Link::SUMMARY_SKIP);
                } else {
                    $link->setAiSummary($summary);
                    $link->setSummaryStatus(Link::SUMMARY_DONE);
                    $output->writeln(\sprintf('#%d summarized', (int) $link->getId()));
                }
            } catch (\Throwable $e) {
                $link->setSummaryStatus(Link::SUMMARY_FAILED);
                $link->setLastError('summary: '.$e->getMessage());
                $this->aiLogger->error('ai summarization failed', ['link' => $link->getId(), 'err' => $e->getMessage()]);
            }
            $this->em->flush();
        }
        $output->writeln(\sprintf('%d links processed', \count($links)));

        return Command::SUCCESS;
    }
}
