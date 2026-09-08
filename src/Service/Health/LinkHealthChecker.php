<?php

declare(strict_types=1);

namespace App\Service\Health;

use App\Entity\Link;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a URL and maps the HTTP status to a Link health value.
 * Uses the Symfony HttpClient (curl transport when the ext is available).
 */
final readonly class LinkHealthChecker
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    /**
     * @return array{status: string, httpStatus: int|null, error: string|null}
     */
    public function check(string $url): array
    {
        // GET (not HEAD): many servers reject/ignore HEAD and would look "dead".
        $response = $this->client->request('GET', $url, [
            'timeout' => 10.0,        // inactivity timeout
            'max_duration' => 20.0,   // hard cap on the whole request
            'max_redirects' => 10,
            'headers' => [
                'User-Agent' => 'SymfonyBookmarks/1.0 (+link-health-check)',
                'Accept' => '*/*',
            ],
        ]);

        try {
            // getStatusCode() waits only for the response headers and never throws on
            // a 4xx/5xx code — it only throws on a transport error (DNS, TLS, timeout).
            $code = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $response->cancel();

            return ['status' => Link::HEALTH_ERROR, 'httpStatus' => null, 'error' => $e->getMessage()];
        }

        $response->cancel(); // we only needed the status line — don't download the body

        // Only 404/410 mean the resource is really gone. Other 4xx (401/403/405/429…)
        // are ambiguous — a bare curl request is often bot-blocked (403) or rate-limited
        // (429), which does NOT mean the link is dead — so those map to "error", not "dead".
        $status = match (true) {
            $code >= 200 && $code < 400 => Link::HEALTH_ALIVE,
            404 === $code, 410 === $code => Link::HEALTH_DEAD,
            default => Link::HEALTH_ERROR, // other 4xx (auth/forbidden/rate-limit) and 5xx
        };

        return ['status' => $status, 'httpStatus' => $code, 'error' => null];
    }
}
