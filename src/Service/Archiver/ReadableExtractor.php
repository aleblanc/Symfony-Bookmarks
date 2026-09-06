<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;

final class ReadableExtractor
{
    public function extract(string $html, string $url): ReadableResult
    {
        $config = new Configuration(originalURL: $url);
        $reader = new Readability($config);
        try {
            $article = $reader->parse($html);

            return new ReadableResult(
                title: $article->title,
                textContent: $article->textContent ?? '',
                excerpt: $article->excerpt ?? '',
            );
        } catch (\Throwable) {
            return new ReadableResult('', '', '');
        }
    }
}
