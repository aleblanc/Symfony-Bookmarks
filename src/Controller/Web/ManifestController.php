<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ManifestController extends AbstractController
{
    /**
     * Served dynamically (not a static public/ file) so asset() / the request
     * base path inject the reverse-proxy sub-path into every icon URL and into
     * start_url/scope. A static manifest can only hold relative paths, which
     * some browsers resolve incorrectly under a sub-path.
     */
    #[Route('/site.webmanifest', name: 'site_manifest')]
    public function __invoke(): Response
    {
        $response = $this->render('site.webmanifest.twig');
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }
}
