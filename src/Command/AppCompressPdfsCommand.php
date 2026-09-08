<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ArchiveAsset;
use App\Repository\ArchiveAssetRepository;
use App\Service\Archiver\PdfCompressor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:compress-pdfs',
    description: 'Recompress archived PDFs with Ghostscript and report the disk space saved (dry-run unless --apply)',
)]
final class AppCompressPdfsCommand extends Command
{
    public function __construct(
        private readonly PdfCompressor $compressor,
        private readonly ArchiveAssetRepository $assets,
        private readonly EntityManagerInterface $em,
        private readonly string $archiveDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('quality', null, InputOption::VALUE_REQUIRED, 'Ghostscript preset: '.implode(' | ', PdfCompressor::QUALITIES), 'ebook');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Overwrite the PDFs on disk (default: measure only, files untouched)');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max PDFs to process (default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->compressor->isEnabled()) {
            $io->error('Ghostscript (gs) not found. Install it (e.g. apt install ghostscript) or set its path.');

            return Command::FAILURE;
        }

        $quality = (string) $input->getOption('quality');
        if (!\in_array($quality, PdfCompressor::QUALITIES, true)) {
            $io->error(\sprintf('Invalid --quality "%s". Choose one of: %s', $quality, implode(', ', PdfCompressor::QUALITIES)));

            return Command::FAILURE;
        }
        $apply = (bool) $input->getOption('apply');
        $limit = null !== $input->getOption('limit') ? max(0, (int) $input->getOption('limit')) : null;

        $pdfs = $this->assets->findByKind(ArchiveAsset::KIND_PDF);
        if (null !== $limit) {
            $pdfs = \array_slice($pdfs, 0, $limit);
        }
        if ([] === $pdfs) {
            $io->warning('No PDF assets found.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Compressing %d PDF(s) with quality=%s (%s)', \count($pdfs), $quality, $apply ? 'APPLY — files will be overwritten' : 'dry-run — files untouched'));

        $rows = [];
        $totalBefore = 0;
        $totalAfter = 0;
        $shrunk = 0;

        foreach ($pdfs as $asset) {
            $path = \sprintf('%s/%s', $this->archiveDir, $asset->getRelativePath());
            if (!is_file($path)) {
                $io->writeln(\sprintf('  <comment>missing</comment> %s', $asset->getRelativePath()));
                continue;
            }

            try {
                $result = $this->compressor->compress($path, $quality, $apply);
            } catch (\Throwable $e) {
                $io->writeln(\sprintf('  <error>failed</error> %s (%s)', $asset->getRelativePath(), $e->getMessage()));
                continue;
            }

            $totalBefore += $result->sizeBefore;
            $totalAfter += $result->sizeAfter;

            if ($result->bytesSaved() > 0) {
                ++$shrunk;
                if ($result->replaced) {
                    $asset->setSizeBytes($result->sizeAfter);
                }
                $rows[] = [
                    $asset->getRelativePath(),
                    $this->fmt($result->sizeBefore),
                    $this->fmt($result->sizeAfter),
                    $this->fmt($result->bytesSaved()),
                    \sprintf('%d%%', (int) round($result->bytesSaved() / $result->sizeBefore * 100)),
                ];
            }
        }

        if ($apply) {
            $this->em->flush();
        }

        if ([] !== $rows) {
            $io->table(['file', 'before', 'after', 'saved', '%'], $rows);
        }

        $saved = max(0, $totalBefore - $totalAfter);
        $pct = $totalBefore > 0 ? (int) round($saved / $totalBefore * 100) : 0;
        $io->success(\sprintf(
            '%d/%d PDF(s) shrank. Total %s → %s — saved %s (%d%%). %s',
            $shrunk,
            \count($pdfs),
            $this->fmt($totalBefore),
            $this->fmt($totalAfter),
            $this->fmt($saved),
            $pct,
            $apply ? 'Files overwritten.' : 'Dry-run: re-run with --apply to keep the savings.',
        ));

        return Command::SUCCESS;
    }

    private function fmt(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return \sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return \sprintf('%.1f KB', $bytes / 1024);
        }

        return $bytes.' B';
    }
}
