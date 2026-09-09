<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use Symfony\Component\Process\Process;

/**
 * Rasterises the first page of a PDF into an image using ImageMagick (`convert`
 * or `magick`). Lets us derive a screenshot and a card thumbnail from the PDF
 * Chrome already produced, instead of re-loading the whole page in Chrome again
 * (and instead of downloading the remote og:image) — saving one full page load
 * per link.
 *
 * ImageMagick reads PDFs through Ghostscript, and many installs disable the PDF
 * coder in policy.xml. canRender() therefore probes with a real tiny PDF and
 * caches the result; callers fall back to Chrome/og:image when it returns false.
 */
final class PdfImageRenderer
{
    /** A minimal single-page PDF used to probe PDF rasterisation support. */
    private const PROBE_PDF = "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 120 120]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /** @var list<string> */
    private array $candidates;
    private ?string $cachedBinary = null;
    private bool $binaryResolved = false;
    private ?bool $canRender = null;

    /**
     * @param list<string>|null $extraCandidates for tests
     */
    public function __construct(
        private readonly string $overridePath = '',
        ?array $extraCandidates = null,
    ) {
        $this->candidates = $extraCandidates ?? [
            $this->overridePath,
            '/usr/bin/convert',
            '/usr/local/bin/convert',
            '/opt/homebrew/bin/convert',
            '/usr/bin/magick',
            '/usr/local/bin/magick',
            '/opt/homebrew/bin/magick',
        ];
    }

    public function find(): ?string
    {
        if ($this->binaryResolved) {
            return $this->cachedBinary;
        }
        $this->binaryResolved = true;
        foreach ($this->candidates as $path) {
            if ('' !== $path && is_file($path) && is_executable($path)) {
                return $this->cachedBinary = $path;
            }
        }

        return $this->cachedBinary = null;
    }

    /**
     * True only if a binary exists AND can actually rasterise a PDF (Ghostscript
     * present and PDF allowed by ImageMagick's policy).
     */
    public function canRender(): bool
    {
        if (null !== $this->canRender) {
            return $this->canRender;
        }
        $binary = $this->find();
        if (null === $binary) {
            return $this->canRender = false;
        }

        // tempnam() creates the base file with no extension; the .pdf/.png paths
        // derive from it. Clean up all three, and only unlink what actually exists
        // so a missing .png (rasterise failed) doesn't log a silenced warning.
        $base = tempnam(sys_get_temp_dir(), 'improbe_');
        $pdf = $base.'.pdf';
        $png = $base.'.png';
        file_put_contents($pdf, self::PROBE_PDF);
        try {
            $this->canRender = $this->rasterise($binary, $pdf, $png, 72);
        } finally {
            foreach ([$base, $pdf, $png] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $this->canRender;
    }

    /**
     * Rasterises page 1 of $pdfPath into $outputPath at the given target width.
     * Returns true on success.
     */
    public function renderPage(string $pdfPath, string $outputPath, int $width): bool
    {
        $binary = $this->find();
        if (null === $binary) {
            return false;
        }

        return $this->rasterise($binary, $pdfPath, $outputPath, $width);
    }

    private function rasterise(string $binary, string $pdfPath, string $outputPath, int $width): bool
    {
        try {
            // -density before the input controls PDF rasterisation resolution;
            // -flatten composites onto white; [0] selects the first page.
            $process = new Process([
                $binary,
                '-density', '150',
                $pdfPath.'[0]',
                '-background', 'white',
                '-flatten',
                '-resize', $width.'x',
                '-strip',
                $outputPath,
            ]);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful() && is_file($outputPath) && (int) filesize($outputPath) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
