<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class AutoTagger
{
    public function __construct(private readonly AgentInterface $taggerAgent)
    {
    }

    /**
     * @return list<string>
     */
    public function suggest(string $title, string $textContent, int $max = 5): array
    {
        $prompt = sprintf(
            "Return %d short lowercase tags for the following page, strictly as a JSON array of strings (no prose).\nTitle: %s\nContent: %s",
            $max,
            $title,
            mb_substr($textContent, 0, 2000),
        );
        $bag = new MessageBag(Message::ofUser($prompt));
        $execution = $this->taggerAgent->call($bag);
        $content = $execution->getContent();
        $raw = trim(\is_string($content) ? $content : '');
        $raw = (string) preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $decoded = json_decode(trim($raw), true);
        if (!\is_array($decoded)) {
            return [];
        }
        $tags = array_slice(
            array_values(array_filter(array_map(static fn ($v): string => (string) $v, $decoded))),
            0,
            $max,
        );

        return array_map(static fn (string $t): string => strtolower(trim($t)), $tags);
    }
}
