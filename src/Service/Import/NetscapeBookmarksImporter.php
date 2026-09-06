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
    ) {
    }

    /**
     * Imports a Netscape-format bookmarks HTML export into $dashboard.
     * Each <H3> folder becomes a Collection; each <A HREF> becomes a Link.
     *
     * @return array{collections: int, links: int}
     */
    public function import(string $html, Dashboard $dashboard): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $stats = ['collections' => 0, 'links' => 0];
        $currentCollection = $this->collections->findOneBy(['name' => 'Imported', 'dashboard' => $dashboard])
            ?? new Collection('Imported', $dashboard);
        $this->em->persist($currentCollection);

        /** @var \DOMNodeList<\DOMElement> $nodes */
        $nodes = $xpath->query('//dt/*') ?: new \DOMNodeList();
        foreach ($nodes as $node) {
            $tag = strtolower($node->nodeName);
            if ('h3' === $tag) {
                $name = trim($node->textContent);
                if ('' === $name) {
                    continue;
                }
                $existing = $this->collections->findOneBy(['name' => $name, 'dashboard' => $dashboard]);
                if (null === $existing) {
                    $currentCollection = new Collection($name, $dashboard);
                    $this->em->persist($currentCollection);
                    ++$stats['collections'];
                } else {
                    $currentCollection = $existing;
                }
            } elseif ('a' === $tag) {
                $href = $node->getAttribute('href');
                if ('' === $href || false === filter_var($href, \FILTER_VALIDATE_URL)) {
                    continue;
                }
                $link = new Link($href, $currentCollection);
                $title = trim($node->textContent);
                if ('' !== $title) {
                    $link->setName($title);
                }
                $this->em->persist($link);
                ++$stats['links'];
            }
        }
        $this->em->flush();

        return $stats;
    }
}
