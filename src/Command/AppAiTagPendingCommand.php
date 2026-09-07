<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Link;
use App\Repository\LinkRepository;
use App\Repository\TagRepository;
use App\Service\Ai\AutoTagger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai-tag-pending', description: 'Auto-tag links with ai_status = pending via the configured LLM agent')]
#[AsCronTask('*/10 * * * *', description: 'AI-tag pending links')]
final class AppAiTagPendingCommand extends Command
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly TagRepository $tagRepository,
        private readonly AutoTagger $tagger,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $aiLogger,
        private readonly bool $enabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max links per run', '20');
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Requeue links whose AI tagging previously failed, then process them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->enabled) {
            $output->writeln('AI disabled (APP_AI_ENABLED=false)');

            return Command::SUCCESS;
        }
        if ($input->getOption('retry-failed')) {
            $reset = $this->links->resetFailedAiStatus();
            $output->writeln(\sprintf('%d failed link(s) requeued', $reset));
        }
        $links = $this->links->findPendingAiEnrichment((int) $input->getOption('limit'));
        /** @var array<int, list<string>> $existingByDashboard */
        $existingByDashboard = [];
        foreach ($links as $link) {
            $dashboard = $link->getCollection()->getDashboard();
            $existing = $existingByDashboard[(int) $dashboard->getId()] ??= $this->tagRepository->namesForDashboard($dashboard);
            try {
                $suggested = $this->tagger->suggest($link->getName() ?? '', $link->getTextContent() ?? '', $existing);
                foreach ($suggested as $name) {
                    $tag = $this->tagRepository->findOrCreate($name, $dashboard);
                    $link->addTag($tag);
                }
                $link->setAiStatus(Link::AI_DONE);
                $output->writeln(\sprintf('#%d tagged: %s', (int) $link->getId(), implode(',', $suggested)));
            } catch (\Throwable $e) {
                $link->setAiStatus(Link::AI_FAILED);
                $link->setLastError('ai: '.$e->getMessage());
                $this->aiLogger->error('ai tagging failed', ['link' => $link->getId(), 'err' => $e->getMessage()]);
            }
            $this->em->flush();
        }
        $output->writeln(\sprintf('%d links processed', \count($links)));

        return Command::SUCCESS;
    }
}
