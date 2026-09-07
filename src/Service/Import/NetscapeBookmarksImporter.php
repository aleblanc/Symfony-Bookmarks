<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Entity\Link;
use App\Repository\CollectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class NetscapeBookmarksImporter
{
    private const ROOT_FOLDER = 'Imported';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollectionRepository $collections,
        private readonly NetscapeBookmarkParser $parser,
    ) {
    }

    /**
     * Imports a Netscape-format bookmarks HTML export into $dashboard, rebuilding
     * the folder hierarchy: each nested <H3> folder becomes a Collection whose
     * parent is the enclosing folder; each <A HREF> becomes a Link in its folder.
     *
     * When $selectedIds is provided, only entries whose folder matches one of the
     * selected folder ids (see NetscapeBookmarkParser::pathId) are imported; null
     * imports everything.
     *
     * @param list<string>|null $selectedIds
     *
     * @return array{collections: int, links: int}
     */
    public function import(string $html, Dashboard $dashboard, ?array $selectedIds = null): array
    {
        $stats = ['collections' => 0, 'links' => 0];
        $selected = null === $selectedIds ? null : array_flip($selectedIds);

        /** @var array<string, Collection> $cache "<parentId>/<name>" => Collection */
        $cache = [];

        foreach ($this->parser->parse($html, self::ROOT_FOLDER) as $entry) {
            if (null !== $selected && !isset($selected[$this->parser->pathId($entry['folders'])])) {
                continue;
            }
            $path = [] === $entry['folders'] ? [self::ROOT_FOLDER] : $entry['folders'];

            $collection = null;
            foreach ($path as $name) {
                $collection = $this->resolveCollection($name, $collection, $dashboard, $cache, $stats);
            }

            $link = new Link($entry['url'], $collection);
            if ('' !== $entry['title']) {
                $link->setName($entry['title']);
            }
            $this->em->persist($link);
            ++$stats['links'];
        }

        $this->em->flush();

        return $stats;
    }

    /**
     * @param array<string, Collection>          $cache
     * @param array{collections: int, links: int} $stats
     */
    private function resolveCollection(string $name, ?Collection $parent, Dashboard $dashboard, array &$cache, array &$stats): Collection
    {
        $key = ($parent?->getId() ?? 0).'/'.$name;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $existing = $this->collections->findOneBy(['name' => $name, 'parent' => $parent, 'dashboard' => $dashboard]);
        if (null === $existing) {
            $existing = new Collection($name, $dashboard);
            $existing->setParent($parent);
            $this->em->persist($existing);
            $this->em->flush(); // assign an id so nested children can reference it
            ++$stats['collections'];
        }

        return $cache[$key] = $existing;
    }
}
