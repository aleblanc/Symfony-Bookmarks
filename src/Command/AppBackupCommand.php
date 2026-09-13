<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Backs up the SQLite database with `sqlite3 … ".backup"` (a consistent copy
 * even while the app is running) into var/backup/, then prunes old backups on a
 * GFS retention: keep the newest plus the ones closest to 12h / 24h / 48h / 7d /
 * 14d old; delete the rest.
 */
#[AsCommand(name: 'app:backup', description: 'Back up the SQLite database (sqlite3 .backup) and prune old backups.')]
#[AsCronTask('0 */12 * * *', description: 'Database backup every 12h')]
final class AppBackupCommand extends Command
{
    /** Retention target ages in seconds: 12h, 24h, 48h, 7d, 14d. */
    private const RETENTION = [43200, 86400, 172800, 604800, 1209600];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(APP_SQLITE_PATH)%')]
        private readonly string $sqlite3,
        #[Autowire(service: 'monolog.logger.cron')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('prune-only', null, InputOption::VALUE_NONE, 'Skip the backup, only apply the retention pruning');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->projectDir.'/var/backup';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->writeln(\sprintf('<error>Cannot create backup dir %s</error>', $dir));

            return Command::FAILURE;
        }

        if (!$input->getOption('prune-only')) {
            $dbPath = $this->databasePath();
            if (null === $dbPath || !is_file($dbPath)) {
                $output->writeln('<error>SQLite database file not found (not a SQLite connection?)</error>');

                return Command::FAILURE;
            }

            $dest = \sprintf('%s/backup-%s.sqlite', $dir, (new \DateTimeImmutable())->format('Ymd-His'));
            $process = new Process([$this->sqlite3, $dbPath, ".backup '".$dest."'"]);
            $process->setTimeout(300);
            try {
                $process->mustRun();
            } catch (\Throwable $e) {
                $this->logger->error('backup failed', ['err' => $e->getMessage()]);
                $output->writeln(\sprintf('<error>Backup failed: %s</error>', $e->getMessage()));

                return Command::FAILURE;
            }
            if (!is_file($dest) || filesize($dest) === 0) {
                $output->writeln('<error>Backup produced no file</error>');

                return Command::FAILURE;
            }
            $output->writeln(\sprintf('backup written: %s (%d bytes)', basename($dest), (int) filesize($dest)));
        }

        $deleted = $this->prune($dir, $output);
        $this->logger->info('backup run', ['deleted' => $deleted, 'dir' => $dir]);

        return Command::SUCCESS;
    }

    /**
     * GFS pruning: keep the newest backup and, for each retention age, the newest
     * backup at least that old. Delete everything else. Returns the count removed.
     */
    private function prune(string $dir, OutputInterface $output): int
    {
        $files = glob($dir.'/backup-*.sqlite') ?: [];
        if ([] === $files) {
            return 0;
        }
        // Newest first, by mtime (independent of the filename).
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $now = time();
        $keep = [$files[0] => true]; // always keep the newest
        foreach (self::RETENTION as $target) {
            foreach ($files as $f) {
                if ($now - (int) filemtime($f) >= $target) {
                    $keep[$f] = true;
                    break; // newest file at least $target old
                }
            }
        }

        $deleted = 0;
        foreach ($files as $f) {
            if (!isset($keep[$f]) && @unlink($f)) {
                ++$deleted;
                $output->writeln('pruned: '.basename($f));
            }
        }
        $output->writeln(\sprintf('%d kept, %d pruned', \count($keep), $deleted));

        return $deleted;
    }

    /** Absolute path of the SQLite database file, or null if not a SQLite connection. */
    private function databasePath(): ?string
    {
        $path = $this->connection->getParams()['path'] ?? null;

        return \is_string($path) ? $path : null;
    }
}
