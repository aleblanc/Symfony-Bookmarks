<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ManifestController extends AbstractController
{
    public function __construct(
        private readonly Packages $assets,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Served dynamically (not a static public/ file) so asset() / the request
     * base path inject the reverse-proxy sub-path into every icon URL and into
     * start_url/scope. Built as a PHP array and JSON-encoded with unescaped
     * slashes so paths read "/bookmarks/icon.png", not "\/bookmarks\/icon.png"
     * (both are valid JSON, but some manifest consumers choke on the escapes).
     */
    #[Route('/site.webmanifest', name: 'site_manifest')]
    public function __invoke(): JsonResponse
    {
        $base = ($this->requestStack->getCurrentRequest()?->getBasePath() ?? '').'/';

        $manifest = [
            'name' => 'Symfony Bookmarks',
            'short_name' => $this->translator->trans('app.name'),
            'description' => 'Self-hosted, single-user bookmark manager.',
            'lang' => 'fr',
            'dir' => 'ltr',
            'start_url' => $base,
            'scope' => $base,
            'id' => $base,
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#fbf7f0',
            'theme_color' => '#e11d48',
            'icons' => [
                ['src' => $this->assets->getUrl('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->assets->getUrl('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->assets->getUrl('icon-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => $this->assets->getUrl('icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => $this->assets->getUrl('favicon.svg'), 'sizes' => '16x16 32x32 48x48 64x64 96x96 128x128 192x192 256x256 384x384 512x512', 'type' => 'image/svg+xml'],
            ],
        ];

        $response = new JsonResponse($manifest);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }
}
