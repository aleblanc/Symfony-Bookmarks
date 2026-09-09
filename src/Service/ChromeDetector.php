<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChromeDetector
{
    /** @var list<string> */
    private array $candidates;
    private ?string $cached = null;
    private bool $resolved = false;

    /**
     * @param list<string>|null $extraCandidates for tests
     */
    public function __construct(
        #[Autowire('%env(APP_CHROME_PATH)%')]
        private readonly string $overridePath = '',
        ?array $extraCandidates = null,
    ) {
        $this->candidates = $extraCandidates ?? [
            $this->overridePath,
            // Linux (target: Raspberry Pi)
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/local/bin/chromium',
            '/snap/bin/chromium',
            // macOS (local dev)
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/opt/homebrew/bin/chromium',
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

    /**
     * @return array<string, bool>
     */
    public function availableFeatures(): array
    {
        $chrome = $this->isAvailable();

        return [
            'screenshot' => $chrome,
            'pdf' => $chrome,
            'singlefile' => $chrome && $this->hasSingleFileCli(),
            'readable' => true,
            'raw_html' => true,
        ];
    }

    private function hasSingleFileCli(): bool
    {
        $out = @shell_exec('command -v single-file 2>/dev/null');

        return \is_string($out) && '' !== trim($out);
    }
}
