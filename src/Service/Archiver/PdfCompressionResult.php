<?php

declare(strict_types=1);

namespace App\Service\Archiver;

/**
 * Outcome of a single PDF recompression attempt. Immutable value object so the
 * command can tally totals without juggling loosely-typed arrays.
 */
final class PdfCompressionResult
{
    private function __construct(
        public readonly int $sizeBefore,
        public readonly int $sizeAfter,
        public readonly bool $replaced,
        public readonly string $reason,
    ) {
    }

    public static function skipped(int $sizeBefore, string $reason): self
    {
        return new self($sizeBefore, $sizeBefore, false, $reason);
    }

    public static function compressed(int $sizeBefore, int $sizeAfter, bool $replaced): self
    {
        return new self($sizeBefore, $sizeAfter, $replaced, $replaced ? 'replaced' : 'measured');
    }

    public function bytesSaved(): int
    {
        return max(0, $this->sizeBefore - $this->sizeAfter);
    }
}
