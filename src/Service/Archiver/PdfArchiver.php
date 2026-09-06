<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use App\Entity\ArchiveAsset;
use App\Service\ChromeDetector;
use Symfony\Component\Process\Process;

final class PdfArchiver implements AssetArchiverInterface
{
    public function __construct(private readonly ChromeDetector $chrome)
    {
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
    }
}
