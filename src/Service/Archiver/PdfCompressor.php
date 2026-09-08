<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use Symfony\Component\Process\Process;

/**
 * Recompresses a PDF with Ghostscript. Chrome's `--print-to-pdf` embeds images at
 * full resolution without recompression, so pages heavy in visuals produce large
 * files; `gs -dPDFSETTINGS=/ebook` downsamples and re-encodes them. Text-only PDFs
 * barely shrink (or grow), so the result is only written back when it is smaller.
 */
final class PdfCompressor
{
    /** Ghostscript's -dPDFSETTINGS presets, smallest quality first. */
    public const QUALITIES = ['screen', 'ebook', 'printer', 'prepress'];

    public function __construct(private readonly GhostscriptDetector $ghostscript)
    {
    }

    public function isEnabled(): bool
    {
        return $this->ghostscript->isAvailable();
    }

    /**
     * @param string $quality one of {@see self::QUALITIES}
     *
     * @throws \RuntimeException when Ghostscript is unavailable
     */
    public function compress(string $path, string $quality = 'ebook', bool $apply = true): PdfCompressionResult
    {
        $gs = $this->ghostscript->find();
        if (null === $gs) {
            throw new \RuntimeException('ghostscript binary not available');
        }
        if (!\in_array($quality, self::QUALITIES, true)) {
            throw new \InvalidArgumentException(\sprintf('invalid quality "%s"', $quality));
        }

        $before = filesize($path);
        if (false === $before) {
            return PdfCompressionResult::skipped(0, 'unreadable');
        }

        $tmp = $path.'.gs-tmp';
        $process = new Process([
            $gs,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/'.$quality,
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-dDetectDuplicateImages=true',
            '-sOutputFile='.$tmp,
            $path,
        ]);
        $process->setTimeout(180);

        try {
            $process->mustRun();
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new \RuntimeException('ghostscript failed: '.$e->getMessage(), 0, $e);
        }

        $after = filesize($tmp);
        if (false === $after || $after <= 0) {
            @unlink($tmp);

            return PdfCompressionResult::skipped($before, 'empty-output');
        }

        // Guard: never grow a file. Text-heavy PDFs often come out larger.
        if ($after >= $before) {
            @unlink($tmp);

            return PdfCompressionResult::compressed($before, $after, false);
        }

        if ($apply) {
            if (!@rename($tmp, $path)) {
                @unlink($tmp);

                throw new \RuntimeException('could not replace '.$path);
            }

            return PdfCompressionResult::compressed($before, $after, true);
        }

        @unlink($tmp);

        return PdfCompressionResult::compressed($before, $after, false);
    }
}
