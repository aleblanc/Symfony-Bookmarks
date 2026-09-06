<?php

declare(strict_types=1);

namespace App\Service\Favicon;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FaviconFetcher
{
    private HttpClientInterface $http;

    public function __construct(
        private readonly string $publicDir,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create(['timeout' => 10, 'max_redirects' => 3]);
    }

    /**
     * Returns a public-relative path like `/assets/favicons/<md5>.ico`, or null on failure.
     */
    public function fetch(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host)) {
            return null;
        }
        $slug = md5($host);
        $dir = $this->publicDir.'/assets/favicons';
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return null;
        }
        $localAbs = $dir.'/'.$slug.'.ico';
        $publicPath = '/assets/favicons/'.$slug.'.ico';
        if (file_exists($localAbs)) {
            return $publicPath;
        }
        foreach ([
            'https://'.$host.'/favicon.ico',
            'https://www.google.com/s2/favicons?domain='.$host.'&sz=64',
        ] as $source) {
            try {
                $response = $this->http->request('GET', $source);
                if (200 !== $response->getStatusCode()) {
                    continue;
                }
                $bytes = $response->getContent(false);
                if ('' === $bytes) {
                    continue;
                }
                file_put_contents($localAbs, $bytes);

                return $publicPath;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
