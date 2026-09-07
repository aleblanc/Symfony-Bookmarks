<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Parses a Netscape-format bookmarks HTML export into a flat list of entries,
 * each carrying the name of the folder (<H3>) it belongs to.
 *
 * Netscape files never close their <DT> tags, so feeding them to a DOM/HTML
 * parser produces an unreliable tree whose nesting depends on the content
 * (a root-level link, for instance, swallows the following folders into its
 * own <dt>). We therefore ignore the tree entirely and scan the significant
 * tags in document order, tracking folder depth with an explicit stack keyed
 * off the <DL> / </DL> markers — which *are* balanced in the format.
 */
final class NetscapeBookmarkParser
{
    private const TOKEN = '/(?P<dl_open><dl\b[^>]*>)|(?P<dl_close><\/dl\s*>)|<h3\b[^>]*>(?P<h3>.*?)<\/h3>|<a\b[^>]*\bhref\s*=\s*(?P<q>["\'])(?P<href>.*?)(?P=q)[^>]*>(?P<atext>.*?)<\/a>/is';

    /**
     * @return list<array{url: string, title: string, folders: list<string>}>
     *                                                  folders = ancestor folder names from
     *                                                  top-level down to the containing folder
     *                                                  (empty for a document-root bookmark)
     */
    public function parse(string $html, string $rootFolder): array
    {
        preg_match_all(self::TOKEN, $html, $matches, \PREG_SET_ORDER);

        /** @var list<string> $stack folder names, deepest last; index 0 is the root sentinel */
        $stack = [];
        $pending = $rootFolder; // folder name the next <DL> will open
        $entries = [];

        foreach ($matches as $m) {
            if ('' !== ($m['dl_open'] ?? '')) {
                $stack[] = $pending;
                $pending = $stack[\count($stack) - 1];
                continue;
            }
            if ('' !== ($m['dl_close'] ?? '')) {
                array_pop($stack);
                $pending = $stack[\count($stack) - 1] ?? $rootFolder;
                continue;
            }
            if (isset($m['h3']) && '' !== $m['h3']) {
                $name = $this->text($m['h3']);
                if ('' !== $name) {
                    $pending = $name;
                }
                continue;
            }
            // <a href=...>
            $href = html_entity_decode($m['href'], \ENT_QUOTES | \ENT_HTML5);
            if ('' === $href || false === filter_var($href, \FILTER_VALIDATE_URL)) {
                continue;
            }
            $entries[] = [
                'url' => $href,
                'title' => $this->text($m['atext'] ?? ''),
                // Drop the root sentinel (stack[0]) to get the real folder path.
                'folders' => array_values(\array_slice($stack, 1)),
            ];
        }

        return $entries;
    }

    /**
     * Builds a nested folder tree from parsed entries, for the import preview.
     * Each node carries a base64 id (its folder path) usable as a checkbox value.
     *
     * @param list<array{url: string, title: string, folders: list<string>}> $entries
     *
     * @return array{root_count: int, root_id: string, nodes: list<array{name: string, id: string, count: int, children: array<int, mixed>}>}
     */
    public function folderTree(array $entries): array
    {
        $rootCount = 0;
        /** @var array<string, array{count: int, children: array<string, mixed>}> $tree */
        $tree = [];
        foreach ($entries as $entry) {
            if ([] === $entry['folders']) {
                ++$rootCount;
                continue;
            }
            $ref = &$tree;
            $last = \count($entry['folders']) - 1;
            foreach ($entry['folders'] as $i => $name) {
                if (!isset($ref[$name])) {
                    $ref[$name] = ['count' => 0, 'children' => []];
                }
                if ($i === $last) {
                    ++$ref[$name]['count'];
                }
                $ref = &$ref[$name]['children'];
            }
            unset($ref);
        }

        return [
            'root_count' => $rootCount,
            'root_id' => $this->pathId([]),
            'nodes' => $this->buildNodes($tree, []),
        ];
    }

    /**
     * @param array<string, array{count: int, children: array<string, mixed>}> $nodes
     * @param list<string>                                                      $parentPath
     *
     * @return list<array{name: string, id: string, count: int, children: array<int, mixed>}>
     */
    private function buildNodes(array $nodes, array $parentPath): array
    {
        ksort($nodes, \SORT_NATURAL | \SORT_FLAG_CASE);
        $out = [];
        foreach ($nodes as $name => $data) {
            $path = [...$parentPath, $name];
            /** @var array<string, array{count: int, children: array<string, mixed>}> $children */
            $children = $data['children'];
            $out[] = [
                'name' => $name,
                'id' => $this->pathId($path),
                'count' => $data['count'],
                'children' => $this->buildNodes($children, $path),
            ];
        }

        return $out;
    }

    /**
     * Encodes a folder path as a stable, form-safe id (base64 of the JSON path).
     *
     * @param list<string> $path
     */
    public function pathId(array $path): string
    {
        return base64_encode((string) json_encode($path));
    }

    private function text(string $raw): string
    {
        return trim(html_entity_decode(strip_tags($raw), \ENT_QUOTES | \ENT_HTML5));
    }
}
