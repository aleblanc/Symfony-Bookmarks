<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AutoSummarizer
{
    private const LANGUAGES = ['fr' => 'French', 'en' => 'English'];

    public function __construct(
        #[Autowire(service: 'ai.agent.summarizer')]
        private readonly AgentInterface $summarizerAgent,
        #[Autowire('%env(APP_AI_TAG_LANG)%')]
        private readonly string $language = 'fr',
    ) {
    }

    /**
     * Returns a short summary of the page, or '' when the model gives nothing usable.
     */
    public function summarize(string $title, string $textContent): string
    {
        $language = self::LANGUAGES[$this->language] ?? 'French';
        $prompt = \sprintf(
            "Summarize the following web page in two sentences, written in %s whatever the page's language. Answer with the summary text only, no preamble.\nTitle: %s\nContent: %s",
            $language,
            $title,
            mb_substr($textContent, 0, 4000),
        );
        $execution = $this->summarizerAgent->call(new MessageBag(Message::ofUser($prompt)));
        $content = $execution->getContent();

        return trim(\is_string($content) ? $content : '');
    }
}
