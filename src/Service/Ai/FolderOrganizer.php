<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Collection;
use App\Repository\CollectionRepository;
use App\Repository\LinkRepository;
use App\Service\Ai\Schema\CategoryProposal;
use App\Service\Ai\Schema\LinkAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Factory as JsonSchemaFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

final class FolderOrganizer
{
    public function __construct(
        #[Autowire(service: 'ai.agent.organizer_proposer')]
        private readonly AgentInterface $proposerAgent,
        #[Autowire(service: 'ai.agent.organizer_assigner')]
        private readonly AgentInterface $assignerAgent,
        #[Autowire(service: 'ai.platform.json_schema_factory')]
        private readonly JsonSchemaFactory $schemaFactory,
        private readonly SerializerInterface $serializer,
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $aiLogger,
    ) {
    }

    /**
     * Compact, token-cheap representation of a collection's links for the LLM.
     *
     * @return array{lines: string, ids: list<int>}
     */
    public function buildBookmarkList(Collection $collection): array
    {
        $lines = [];
        $ids = [];
        foreach ($this->links->findForCollection($collection) as $link) {
            $id = (int) $link->getId();
            $title = trim((string) ($link->getName() ?? '')) ?: $link->getUrl();
            // Keep the prompt compact: long titles inflate the token count and
            // leave less room for the model's JSON reply within its context window.
            $lines[] = '#'.$id.' '.mb_substr($title, 0, 80);
            $ids[] = $id;
        }

        return ['lines' => implode("\n", $lines), 'ids' => $ids];
    }

    /** Phase 1: ask the LLM to propose sub-folders for this collection. */
    public function proposeCategories(Collection $collection): CategoryProposal
    {
        $list = $this->buildBookmarkList($collection);
        $prompt = "Bookmarks to organize:\n".$list['lines']
            ."\n\nRespond with JSON matching this schema:\n"
            .json_encode($this->schemaFactory->buildProperties(CategoryProposal::class), \JSON_THROW_ON_ERROR);

        // Try native structured output (response_format); if the model returns a
        // hydrated object, use it directly. Otherwise fall back to parsing the
        // JSON out of the text — robust across any OpenAI-compatible model.
        // Cap the reply so a big folder stays well under the request timeout.
        $content = $this->callAi($this->proposerAgent, $prompt, 1200, CategoryProposal::class);
        if ($content instanceof CategoryProposal) {
            return $content;
        }

        $raw = \is_string($content) ? $content : '';
        try {
            /** @var CategoryProposal $proposal */
            $proposal = $this->serializer->deserialize(self::extractJson($raw), CategoryProposal::class, 'json');
        } catch (\Throwable $e) {
            $this->aiLogger->warning('organizer: could not parse proposal', ['error' => $e->getMessage(), 'raw' => mb_substr($raw, 0, 300)]);

            return new CategoryProposal([]);
        }

        return $proposal;
    }

    /**
     * Phase 2: ask the LLM which of the collection's links belong in the named target.
     *
     * @return list<int> validated link ids (guaranteed subset of the collection)
     */
    public function assignLinks(Collection $collection, string $categoryName, string $categoryDescription): array
    {
        $list = $this->buildBookmarkList($collection);
        $prompt = 'Target folder: '.$categoryName."\nDescription: ".$categoryDescription
            ."\n\nBookmarks:\n".$list['lines']
            ."\n\nRespond with JSON matching this schema:\n"
            .json_encode($this->schemaFactory->buildProperties(LinkAssignment::class), \JSON_THROW_ON_ERROR);

        $content = $this->callAi($this->assignerAgent, $prompt, 1500, LinkAssignment::class);
        if ($content instanceof LinkAssignment) {
            return self::keepKnownIds(array_map('intval', $content->linkIds), $list['ids']);
        }

        $raw = \is_string($content) ? $content : '';
        try {
            /** @var LinkAssignment $assignment */
            $assignment = $this->serializer->deserialize(self::extractJson($raw), LinkAssignment::class, 'json');
        } catch (\Throwable $e) {
            $this->aiLogger->warning('organizer: could not parse assignment', ['error' => $e->getMessage(), 'raw' => mb_substr($raw, 0, 300)]);

            return [];
        }

        return self::keepKnownIds(array_map('intval', $assignment->linkIds), $list['ids']);
    }

    /**
     * Call the agent and return its raw content — a hydrated object when the
     * model honours structured output (response_format), or a string otherwise.
     *
     * @param class-string $responseFormat
     */
    private function callAi(AgentInterface $agent, string $prompt, int $maxTokens, string $responseFormat): string|object
    {
        $options = ['max_tokens' => $maxTokens, 'response_format' => $responseFormat];

        try {
            $content = $agent->call(new MessageBag(Message::ofUser($prompt)), $options)->getContent();
        } catch (\Throwable $e) {
            // Surface the LM Studio response body: a bare "Bad Request" hides the
            // real reason (context overflow, unsupported param, model not loaded…).
            $this->aiLogger->error('organizer: LM Studio call failed', [
                'error' => $e->getMessage(),
                'body' => self::httpBody($e),
                'prompt_chars' => \strlen($prompt),
            ]);

            throw $e;
        }

        return \is_string($content) || \is_object($content) ? $content : '';
    }

    /** Dig the HTTP response body out of an exception chain, if any. */
    private static function httpBody(\Throwable $e): ?string
    {
        for ($cursor = $e; null !== $cursor; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof HttpExceptionInterface) {
                try {
                    return mb_substr($cursor->getResponse()->getContent(false), 0, 1000);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Extract the JSON object from a model reply that may be fenced (```json …```)
     * or wrapped in prose. Falls back to the first {...} span.
     */
    public static function extractJson(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
            $raw = trim($raw);
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if (false !== $start && false !== $end && $end > $start) {
            return substr($raw, $start, $end - $start + 1);
        }

        return $raw;
    }

    /**
     * Create a sub-folder under $parent and move the given links into it, atomically.
     * The child inherits $parent's vault so encrypted links stay in the same vault.
     *
     * @param list<int> $linkIds
     */
    public function applyCategory(Collection $parent, string $name, array $linkIds): Collection
    {
        $child = new Collection($name, $parent->getDashboard());
        $child->setParent($parent);
        $child->setVault($parent->getVault());
        $child->setPosition(\count($this->collections->findChildren($parent)));

        $this->em->persist($child);

        $links = $this->links->findForCollection($parent);
        $known = array_map(static fn ($l): int => (int) $l->getId(), $links);
        $move = array_fill_keys(self::keepKnownIds(array_map('intval', $linkIds), $known), true);
        foreach ($links as $link) {
            if (isset($move[(int) $link->getId()])) {
                $link->setCollection($child);
            }
        }

        $this->em->flush();

        return $child;
    }

    /**
     * Keep only ids that exist in $known, de-duplicated, in input order.
     *
     * @param list<int> $returned
     * @param list<int> $known
     *
     * @return list<int>
     */
    public static function keepKnownIds(array $returned, array $known): array
    {
        $knownSet = array_fill_keys($known, true);
        $seen = [];
        $out = [];
        foreach ($returned as $id) {
            if (isset($knownSet[$id]) && !isset($seen[$id])) {
                $seen[$id] = true;
                $out[] = $id;
            }
        }

        return $out;
    }
}
