<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Lightweight reachability check for the remote LM Studio server. */
final class AiServerProbe
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(LM_STUDIO_HOST_URL)%')]
        private readonly string $hostUrl,
        #[Autowire('%env(bool:APP_AI_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    /**
     * Ping LM Studio's /v1/models endpoint. Cheap and side-effect-free.
     *
     * @return array{enabled: bool, ok: bool, models: int, error: string|null, host: string}
     */
    public function probe(): array
    {
        $out = ['enabled' => $this->enabled, 'ok' => false, 'models' => 0, 'error' => null, 'host' => $this->hostUrl];

        try {
            $response = $this->httpClient->request('GET', rtrim($this->hostUrl, '/').'/v1/models', [
                'timeout' => 4,
                'max_duration' => 5,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $data = $response->toArray(false);
                $out['ok'] = true;
                $out['models'] = \is_array($data['data'] ?? null) ? \count($data['data']) : 0;
            } else {
                $out['error'] = 'HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }
}
