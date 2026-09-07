<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class AutoTagger
{
    /** @var array<string, array{name: string, examples: string}> */
    private const LANGUAGES = [
        'fr' => ['name' => 'French', 'examples' => 'jeu-video, musique, horreur, tutoriel, recette, actualité'],
        'en' => ['name' => 'English', 'examples' => 'video-game, music, horror, tutorial, recipe, news'],
    ];

    public function __construct(
        private readonly AgentInterface $taggerAgent,
        private readonly string $tagLanguage = 'fr',
    ) {
    }

    /**
     * Suggests tags for a page. Existing tags are given to the model so it reuses
     * them (consistent vocabulary) instead of inventing variants, tags come back
     * in the configured language, and years/numbers/junk are dropped defensively.
     *
     * @param list<string> $existingTags existing dashboard tags to prefer
     *
     * @return list<string>
     */
    public function suggest(string $title, string $textContent, array $existingTags = [], int $max = 5): array
    {
        $lang = self::LANGUAGES[$this->tagLanguage] ?? self::LANGUAGES['fr'];
        $existing = [] === $existingTags ? '(none yet)' : implode(', ', \array_slice($existingTags, 0, 200));

        $prompt = \sprintf(
            <<<'PROMPT'
                You tag a web page with %1$d to %2$d topical tags.
                MOST IMPORTANT: every tag MUST be a %3$s word, whatever the page's language.
                Translate the concept to %3$s (e.g. %4$s).
                Other rules:
                - Reuse a tag from the existing list whenever it fits; invent a new one only if none apply.
                - lowercase, singular, one word or hyphenated (never plural/gerund: "jeu" not "jeux", "video-game" not "gaming").
                - NO years, NO dates, NO numbers, NO punctuation, no site names unless essential.
                Existing tags to prefer: %5$s
                Answer with ONLY a JSON array of strings.

                Title: %6$s
                Content: %7$s
                PROMPT,
            min(3, $max),
            $max,
            $lang['name'],
            $lang['examples'],
            $existing,
            $title,
            mb_substr($textContent, 0, 2000),
        );

        $execution = $this->taggerAgent->call(new MessageBag(Message::ofUser($prompt)));
        $content = $execution->getContent();
        $raw = trim(\is_string($content) ? $content : '');
        $raw = (string) preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $decoded = json_decode(trim($raw), true);

        return \is_array($decoded) ? $this->normaliseTags($decoded, $max) : [];
    }

    /**
     * Cleans a raw list of tags: lowercase/hyphenate, drop years/numbers/junk,
     * dedupe, cap at $max. Deterministic — the reliable half of the tagging.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public function normaliseTags(array $values, int $max = 5): array
    {
        $out = [];
        foreach ($values as $value) {
            $tag = $this->normalise((string) $value);
            if (null !== $tag) {
                $out[$tag] = true; // dedupe on key
            }
            if (\count($out) >= $max) {
                break;
            }
        }

        return array_keys($out);
    }

    /** Normalises a raw tag, or returns null if it should be dropped (year, number, junk). */
    private function normalise(string $raw): ?string
    {
        $tag = trim((string) preg_replace('/\s+/', '-', strtolower(trim($raw))), '-');
        $tag = trim((string) preg_replace('/[^\p{L}\p{N}-]+/u', '', $tag), '-');

        if ('' === $tag || mb_strlen($tag) < 2 || mb_strlen($tag) > 30) {
            return null;
        }
        // Drop pure numbers and years (2025, 19xx…).
        if (1 === preg_match('/^\d+$/', $tag)) {
            return null;
        }

        return $tag;
    }
}
