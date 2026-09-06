<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\ArchiveAsset;
use App\Repository\ArchiveAssetRepository;
use App\Repository\LinkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArchiveController extends AbstractController
{
    private const IMAGE_MIME_BY_EXT = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    public function __construct(
        private readonly ArchiveAssetRepository $assets,
        private readonly LinkRepository $links,
        #[Autowire('%env(resolve:APP_ARCHIVE_DIR)%')]
        private readonly string $archiveDir,
    ) {
    }

    /**
     * Streams an archived asset (PDF, screenshot, single-file HTML) from the
     * non-public var/archives directory.
     */
    #[Route('/archives/{id}', name: 'archive_asset_serve', methods: ['GET'])]
    public function serve(int $id): Response
    {
        $asset = $this->assets->find($id) ?? throw $this->createNotFoundException();

        return $this->streamFile($asset->getRelativePath(), match ($asset->getKind()) {
            ArchiveAsset::KIND_PDF => 'application/pdf',
            ArchiveAsset::KIND_SCREENSHOT => 'image/png',
            ArchiveAsset::KIND_SINGLEFILE, ArchiveAsset::KIND_RAW_HTML => 'text/html; charset=UTF-8',
            default => 'application/octet-stream',
        });
    }

    /**
     * Streams the downloaded preview thumbnail (og:image) for a link.
     */
    #[Route('/links/{id}/preview', name: 'link_preview', methods: ['GET'])]
    public function preview(int $id): Response
    {
        $link = $this->links->find($id) ?? throw $this->createNotFoundException();
        $relative = $link->getPreviewImage();
        if (null === $relative) {
            throw $this->createNotFoundException();
        }
        $ext = strtolower(pathinfo($relative, \PATHINFO_EXTENSION));

        return $this->streamFile($relative, self::IMAGE_MIME_BY_EXT[$ext] ?? 'application/octet-stream');
    }

    /**
     * Resolves an archive-relative path and streams it inline, confirming it
     * stays inside the archive root to defend against path traversal. The
     * Content-Type is set explicitly so BinaryFileResponse never reaches for the
     * symfony/mime guesser (an optional dependency we don't ship).
     */
    private function streamFile(string $relativePath, string $contentType): BinaryFileResponse
    {
        $base = rtrim($this->archiveDir, '/\\');
        $real = realpath($base.'/'.$relativePath);
        if (false === $real || !str_starts_with($real, $base.\DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($real);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_INLINE, basename($real));

        return $response;
    }
}
