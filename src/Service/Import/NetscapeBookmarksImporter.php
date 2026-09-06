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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollectionRepository $collections,
        private readonly NetscapeBookmarkParser $parser,
    ) {
    }

    /**
     * Imports a Netscape-format bookmarks HTML export into $dashboard.
     * Each <H3> folder becomes a Collection; each <A HREF> becomes a Link,
     * placed in the collection matching the folder it was nested under.
     *
     * @return array{collections: int, links: int}
     */
    public function import(string $html, Dashboard $dashboard): array
    {
        $stats = ['collections' => 0, 'links' => 0];

        /** @var array<string, Collection> $byName resolved collections for this run */
        $byName = [];

        foreach ($this->parser->parse($html, 'Imported') as $entry) {
            $collection = $this->resolveCollection($entry['folder'], $dashboard, $byName, $stats);

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
     * @param array<string, Collection>            $byName
     * @param array{collections: int, links: int} $stats
     */
    private function resolveCollection(string $name, Dashboard $dashboard, array &$byName, array &$stats): Collection
    {
        if (isset($byName[$name])) {
            return $byName[$name];
        }

        $existing = $this->collections->findOneBy(['name' => $name, 'dashboard' => $dashboard]);
        if (null === $existing) {
            $existing = new Collection($name, $dashboard);
            $this->em->persist($existing);
            ++$stats['collections'];
        }

        return $byName[$name] = $existing;
    }
}
