<?php

declare(strict_types=1);

namespace App\Service\Archiver;

interface AssetArchiverInterface
{
    public function isEnabled(): bool;

    public function archive(string $url, string $outputPath): void;

    public function kind(): string;
}
