<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class AutoSummarizer
{
    public function __construct(private readonly AgentInterface $summarizerAgent)
    {
    }

    /**
     * Returns a short summary of the page, or '' when the model gives nothing usable.
     */
    public function summarize(string $title, string $textContent): string
    {
        $prompt = \sprintf(
            "Summarize the following web page in two sentences. Answer with the summary text only, no preamble.\nTitle: %s\nContent: %s",
            $title,
            mb_substr($textContent, 0, 4000),
        );
        $execution = $this->summarizerAgent->call(new MessageBag(Message::ofUser($prompt)));
        $content = $execution->getContent();

        return trim(\is_string($content) ? $content : '');
    }
}
