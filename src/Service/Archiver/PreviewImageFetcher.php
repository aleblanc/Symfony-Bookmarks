<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extracts the social preview image (og:image / twitter:image) from a page and
 * downloads it next to the link's other archived assets, so bookmark cards can
 * show a thumbnail. Returns an archive-relative path like "12/preview.jpg".
 */
final class PreviewImageFetcher
{
    private const META_PROPERTIES = ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src'];
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    private HttpClientInterface $http;

    public function __construct(
        #[Autowire('%env(resolve:APP_ARCHIVE_DIR)%')]
        private readonly string $archiveDir,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create(['timeout' => 15, 'max_redirects' => 5]);
    }

    /**
     * Downloads the page's preview image into <archiveDir>/<linkId>/preview.<ext>.
     *
     * @return string|null archive-relative path, or null if none found / download failed
     */
    public function fetch(string $html, string $pageUrl, int $linkId): ?string
    {
        $imageUrl = $this->extractImageUrl($html, $pageUrl);
        if (null === $imageUrl) {
            return null;
        }

        try {
            $response = $this->http->request('GET', $imageUrl);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $bytes = $response->getContent(false);
            if ('' === $bytes) {
                return null;
            }
            $mime = strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
            $mime = trim(explode(';', $mime)[0]);
            $ext = self::EXT_BY_MIME[$mime] ?? $this->extensionFromUrl($imageUrl);
        } catch (\Throwable) {
            return null;
        }

        $dir = rtrim($this->archiveDir, '/\\').'/'.$linkId;
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return null;
        }
        $relative = $linkId.'/preview.'.$ext;
        if (false === @file_put_contents($dir.'/preview.'.$ext, $bytes)) {
            return null;
        }

        return $relative;
    }

    /**
     * Resolves the absolute preview-image URL declared in the page, or null.
     */
    public function extractImageUrl(string $html, string $pageUrl): ?string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        foreach (self::META_PROPERTIES as $key) {
            $nodes = $xpath->query(\sprintf('//meta[@property="%1$s" or @name="%1$s"]/@content', $key));
            $content = $nodes instanceof \DOMNodeList && $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';
            if ('' !== $content) {
                return $this->resolveUrl($pageUrl, $content);
            }
        }

        return null;
    }

    private function resolveUrl(string $base, string $ref): string
    {
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $authority = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($ref, '//')) {
            return $scheme.':'.$ref;
        }
        if (str_starts_with($ref, '/')) {
            return $authority.$ref;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        if ('' === $dir) {
            $dir = '/';
        }

        return $authority.$dir.$ref;
    }

    private function extensionFromUrl(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true) ? ('jpeg' === $ext ? 'jpg' : $ext) : 'jpg';
    }
}
