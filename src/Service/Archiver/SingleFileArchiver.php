<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use App\Entity\ArchiveAsset;
use App\Service\ChromeDetector;
use Symfony\Component\Process\Process;

final class SingleFileArchiver implements AssetArchiverInterface
{
    public function __construct(private readonly ChromeDetector $chrome)
    {
    }

    public function isEnabled(): bool
    {
        return $this->chrome->availableFeatures()['singlefile'];
    }

    public function kind(): string
    {
        return ArchiveAsset::KIND_SINGLEFILE;
    }

    public function archive(string $url, string $outputPath): void
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('single-file-cli or chromium not available');
        }
        $chromePath = $this->chrome->find();
        if (null === $chromePath) {
            throw new \RuntimeException('chromium binary vanished at runtime');
        }
        $process = new Process([
            'single-file',
            '--browser-executable-path='.$chromePath,
            '--browser-args=["--headless=new","--no-sandbox","--disable-gpu"]',
            $url,
            $outputPath,
        ]);
        $process->setTimeout(120);
        $process->mustRun();
    }
}
