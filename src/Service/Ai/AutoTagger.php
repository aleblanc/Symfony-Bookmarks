<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class AutoTagger
{
    private const LANGUAGES = ['fr' => 'French', 'en' => 'English'];

    public function __construct(
        private readonly AgentInterface $taggerAgent,
        private readonly string $tagLanguage = 'fr',
    ) {
    }

    /**
     * Suggests tags for a page. Existing tags are given to the model so it reuses
     * them (keeping the vocabulary consistent) instead of inventing variants, and
     * all tags come back in the configured language whatever the page's language.
     *
     * @param list<string> $existingTags existing dashboard tags to prefer
     *
     * @return list<string>
     */
    public function suggest(string $title, string $textContent, array $existingTags = [], int $max = 5): array
    {
        $language = self::LANGUAGES[$this->tagLanguage] ?? 'French';
        $existing = [] === $existingTags ? '(none yet)' : implode(', ', \array_slice($existingTags, 0, 200));

        $prompt = \sprintf(
            <<<'PROMPT'
                Assign %1$d to %2$d topical tags to the web page below.
                Rules:
                - Always answer in %3$s, whatever the page's language.
                - Reuse an existing tag whenever it fits; only invent a new one if none apply.
                - lowercase, singular, single word or hyphenated (e.g. "video-game", never "games"/"gaming").
                - No generic prefixes ("checked-", "todo-"…), no punctuation, no numbering.
                Existing tags (prefer these): %4$s
                Answer strictly as a JSON array of strings, nothing else.

                Title: %5$s
                Content: %6$s
                PROMPT,
            min(3, $max),
            $max,
            $language,
            $existing,
            $title,
            mb_substr($textContent, 0, 2000),
        );

        $execution = $this->taggerAgent->call(new MessageBag(Message::ofUser($prompt)));
        $content = $execution->getContent();
        $raw = trim(\is_string($content) ? $content : '');
        $raw = (string) preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $decoded = json_decode(trim($raw), true);
        if (!\is_array($decoded)) {
            return [];
        }

        $tags = \array_slice(
            array_values(array_filter(array_map(static fn ($v): string => (string) $v, $decoded))),
            0,
            $max,
        );

        // Normalise defensively: lowercase, trim, spaces -> hyphens, dedupe.
        return array_values(array_unique(array_map(
            static fn (string $t): string => trim(preg_replace('/\s+/', '-', strtolower(trim($t))) ?? '', '-'),
            $tags,
        )));
    }
}
