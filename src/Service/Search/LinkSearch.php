<?php

declare(strict_types=1);

namespace App\Service\Search;

use Doctrine\DBAL\Connection;

final class LinkSearch
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<int>
     */
    public function ftsIds(string $query, int $limit = 200): array
    {
        $q = $this->sanitize($query);
        if ('' === $q) {
            return [];
        }
        $rows = $this->db->executeQuery(
            'SELECT rowid FROM links_fts WHERE links_fts MATCH ? ORDER BY rank LIMIT ?',
            [$q, $limit],
        )->fetchFirstColumn();

        return array_map(intval(...), $rows);
    }

    private function sanitize(string $query): string
    {
        $tokens = preg_split('/\s+/', trim($query), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            static fn (string $t): string => '"'.str_replace('"', '""', $t).'"',
            $tokens,
        ));
    }
}
