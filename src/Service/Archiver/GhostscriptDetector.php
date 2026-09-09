<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Locates the Ghostscript (`gs`) binary the same way {@see \App\Service\ChromeDetector}
 * finds Chromium: a fixed candidate list, an optional env override, cached resolution.
 * Ghostscript is optional — when absent, PDF compression is simply skipped.
 */
final class GhostscriptDetector
{
    /** @var list<string> */
    private array $candidates;
    private ?string $cached = null;
    private bool $resolved = false;

    /**
     * @param list<string>|null $extraCandidates for tests
     */
    public function __construct(
        #[Autowire('%env(APP_GHOSTSCRIPT_PATH)%')]
        private readonly string $overridePath = '',
        ?array $extraCandidates = null,
    ) {
        $this->candidates = $extraCandidates ?? [
            $this->overridePath,
            // Linux (target: Raspberry Pi)
            '/usr/bin/gs',
            '/usr/local/bin/gs',
            // macOS (local dev)
            '/opt/homebrew/bin/gs',
            '/usr/local/bin/gs',
        ];
    }

    public function find(): ?string
    {
        if ($this->resolved) {
            return $this->cached;
        }
        $this->resolved = true;
        foreach ($this->candidates as $path) {
            if ('' !== $path && file_exists($path) && is_executable($path)) {
                return $this->cached = $path;
            }
        }

        return $this->cached = null;
    }

    public function isAvailable(): bool
    {
        return null !== $this->find();
    }
}
