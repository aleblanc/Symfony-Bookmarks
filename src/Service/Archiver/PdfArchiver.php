<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use App\Entity\ArchiveAsset;
use App\Service\ChromeDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class PdfArchiver implements AssetArchiverInterface
{
    public function __construct(
        private readonly ChromeDetector $chrome,
        private readonly PdfCompressor $compressor,
        private readonly LoggerInterface $logger,
        private readonly string $compressQuality = 'ebook',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->chrome->availableFeatures()['pdf'];
    }

    public function kind(): string
    {
        return ArchiveAsset::KIND_PDF;
    }

    public function archive(string $url, string $outputPath): void
    {
        $chromePath = $this->chrome->find();
        if (null === $chromePath) {
            throw new \RuntimeException('chromium binary not available');
        }
        $process = new Process([
            $chromePath,
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--print-to-pdf='.$outputPath,
            $url,
        ]);
        $process->setTimeout(90);
        $process->mustRun();

        // Chrome embeds images uncompressed; shrink the PDF in place when
        // Ghostscript is available. Never let a compression hiccup fail the
        // archive — the uncompressed PDF is already a valid capture.
        if ($this->compressor->isEnabled()) {
            try {
                $result = $this->compressor->compress($outputPath, $this->compressQuality, true);
                if ($result->replaced) {
                    $this->logger->info('pdf compressed', [
                        'before' => $result->sizeBefore,
                        'after' => $result->sizeAfter,
                        'saved' => $result->bytesSaved(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('pdf compression skipped', ['err' => $e->getMessage()]);
            }
        }
    }
}
