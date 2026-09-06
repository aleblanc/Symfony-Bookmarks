<?php

declare(strict_types=1);

namespace App\Service\Archiver;

final class ReadableResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $textContent,
        public readonly string $excerpt,
    ) {
    }
}
