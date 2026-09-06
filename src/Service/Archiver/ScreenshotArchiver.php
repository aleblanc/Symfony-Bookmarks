<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use App\Entity\ArchiveAsset;
use App\Service\ChromeDetector;
use Symfony\Component\Process\Process;

final class ScreenshotArchiver implements AssetArchiverInterface
{
    public function __construct(
        private readonly ChromeDetector $chrome,
        private readonly PdfImageRenderer $pdfRenderer,
    ) {
    }

    /**
     * Chrome screenshots are only the fallback: when ImageMagick can rasterise
     * the PDF, ArchiveRunner derives the screenshot from it instead (one less
     * full page load), so this archiver stands down.
     */
    public function isEnabled(): bool
    {
        return $this->chrome->availableFeatures()['screenshot'] && !$this->pdfRenderer->canRender();
    }

    public function kind(): string
    {
        return ArchiveAsset::KIND_SCREENSHOT;
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
            '--hide-scrollbars',
            '--window-size=1280,4000',
            '--screenshot='.$outputPath,
            $url,
        ]);
        $process->setTimeout(90);
        $process->mustRun();
    }
}
