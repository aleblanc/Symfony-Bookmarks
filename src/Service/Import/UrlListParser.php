<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Extracts a clean list of URLs from a free-form pasted text block.
 *
 * The paste may be a numbered list ("1. https://…"), a bulleted list
 * ("- https://…", "* https://…", "• https://…"), a CSV-ish table
 * ("42, https://…" — id first, url last) or simply one URL per line.
 *
 * Any line that does not contain an http/https/ftp/ftps URL is ignored, and
 * whatever numbering / bullet / CSV column precedes the URL on a kept line is
 * dropped automatically (the URL is located wherever it sits on the line).
 */
final class UrlListParser
{
    /**
     * @return list<string> the URLs found, in reading order, without duplicates
     */
    public function parse(string $text): array
    {
        $urls = [];
        $seen = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (1 !== preg_match('~(?:https?|ftps?)://\S+~i', $line, $m)) {
                continue;
            }
            $url = $this->trimTrailingNoise($m[0]);
            if ('' === $url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * Drops trailing punctuation a list/CSV/markdown line commonly leaves glued
     * to the URL — a comma from "url, note", a closing bracket/paren/quote from
     * "[label](url)" or "\"url\"", or a sentence-ending period.
     */
    private function trimTrailingNoise(string $url): string
    {
        return rtrim($url, ".,;:!?)]}>\"'");
    }
}
