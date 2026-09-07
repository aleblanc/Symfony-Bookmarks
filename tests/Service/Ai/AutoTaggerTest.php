<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AutoTagger;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;

final class AutoTaggerTest extends TestCase
{
    private AutoTagger $tagger;

    protected function setUp(): void
    {
        // The agent is unused by normaliseTags(); a bare stub is enough.
        $this->tagger = new AutoTagger($this->createStub(AgentInterface::class), 'fr');
    }

    public function testDropsYearsAndPureNumbers(): void
    {
        self::assertSame(['horreur', 'gaming'], $this->tagger->normaliseTags(['horreur', '2025', 'gaming', '42']));
    }

    public function testLowercasesHyphenatesAndDedupes(): void
    {
        self::assertSame(
            ['music-video', 'k-pop'],
            $this->tagger->normaliseTags(['Music Video', 'K-POP', 'music-video']),
        );
    }

    public function testDropsTooShortOrEmptyAndStripsPunctuation(): void
    {
        self::assertSame(['news'], $this->tagger->normaliseTags(['a', '', '  ', 'news!', 'News']));
    }

    public function testCapsAtMax(): void
    {
        self::assertCount(3, $this->tagger->normaliseTags(['a1', 'b2', 'c3', 'd4', 'e5'], 3));
    }
}
