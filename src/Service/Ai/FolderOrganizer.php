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

final class FolderOrganizer
{
    public function __construct(
        private readonly AgentInterface $proposerAgent,
        private readonly AgentInterface $assignerAgent,
        private readonly JsonSchemaFactory $schemaFactory,
        private readonly LinkRepository $links,
        private readonly CollectionRepository $collections,
        private readonly EntityManagerInterface $em,
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
            $lines[] = '#'.$id.' '.$title;
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

        $result = $this->proposerAgent
            ->call(new MessageBag(Message::ofUser($prompt)), ['response_format' => CategoryProposal::class])
            ->getContent();

        if (!$result instanceof CategoryProposal) {
            $this->aiLogger->warning('organizer: proposer returned unexpected content', ['type' => get_debug_type($result)]);

            return new CategoryProposal([]);
        }

        return $result;
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

        $result = $this->assignerAgent
            ->call(new MessageBag(Message::ofUser($prompt)), ['response_format' => LinkAssignment::class])
            ->getContent();

        if (!$result instanceof LinkAssignment) {
            $this->aiLogger->warning('organizer: assigner returned unexpected content', ['type' => get_debug_type($result)]);

            return [];
        }

        return self::keepKnownIds(array_map('intval', $result->linkIds), $list['ids']);
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
