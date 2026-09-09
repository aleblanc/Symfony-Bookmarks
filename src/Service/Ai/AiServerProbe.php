<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Lightweight reachability check for the remote LM Studio server. */
final class AiServerProbe
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $hostUrl,
        private readonly bool $enabled,
        private readonly string $model = '',
    ) {
    }

    /**
     * Send a real chat completion straight to LM Studio (bypassing the AI
     * abstraction) and return the raw status + body — so a 400's actual reason
     * is visible. $pad repeats filler to approximate a large prompt.
     *
     * @return array{ok: bool, status: int, body: string|null, error: string|null}
     */
    public function chatProbe(int $pad = 0): array
    {
        $out = ['ok' => false, 'status' => 0, 'body' => null, 'error' => null];

        $prompt = 'Reply with a JSON object {"ok": true}. Nothing else.';
        if ($pad > 0) {
            $filler = '';
            for ($i = 1; $i <= $pad; ++$i) {
                $filler .= '#'.$i." example bookmark title about a topic\n";
            }
            $prompt = "Bookmarks:\n".$filler."\nReply with a JSON object {\"ok\": true}. Nothing else.";
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->hostUrl, '/').'/v1/chat/completions', [
                'timeout' => 60,
                'max_duration' => 65,
                'json' => [
                    'model' => $this->model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.2,
                ],
            ]);
            $out['status'] = $response->getStatusCode();
            $out['body'] = mb_substr($response->getContent(false), 0, 2000);
            $out['ok'] = $out['status'] >= 200 && $out['status'] < 300;
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
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
