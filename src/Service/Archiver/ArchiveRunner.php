<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use App\Entity\ArchiveAsset;
use App\Entity\Link;
use App\Service\Favicon\FaviconFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ArchiveRunner
{
    private HttpClientInterface $http;

    /**
     * @param iterable<AssetArchiverInterface> $archivers
     */
    public function __construct(
        private readonly ReadableExtractor $readable,
        #[AutowireIterator('app.archiver')]
        private readonly iterable $archivers,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.archive')]
        private readonly LoggerInterface $logger,
        private readonly FaviconFetcher $favicon,
        private readonly PreviewImageFetcher $preview,
        private readonly PdfImageRenderer $pdfRenderer,
        #[Autowire('%env(resolve:APP_ARCHIVE_DIR)%')]
        private readonly string $archiveDir,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create(['timeout' => 30, 'max_redirects' => 5]);
    }

    public function run(Link $link): void
    {
        $link->setStatus(Link::STATUS_ARCHIVING);
        $this->em->flush();

        try {
            $html = $this->http->request('GET', $link->getUrl(), [
                'headers' => ['User-Agent' => 'Mozilla/5.0 BookmarksArchiver/1.0'],
            ])->getContent();

            $extracted = $this->readable->extract($html, $link->getUrl());
            if ('' !== $extracted->title && null === $link->getName()) {
                $link->setName($extracted->title);
            }
            if ('' !== $extracted->textContent) {
                $link->setTextContent(strip_tags($extracted->textContent));
            }
            if ('' !== $extracted->excerpt && null === $link->getDescription()) {
                $link->setDescription($extracted->excerpt);
            }

            $iconPath = $this->favicon->fetch($link->getUrl());
            if (null !== $iconPath) {
                $link->setIconPath($iconPath);
            }

            $baseDir = \sprintf('%s/%d', $this->archiveDir, (int) $link->getId());
            if (!is_dir($baseDir)) {
                @mkdir($baseDir, 0o755, true);
            }

            $pdfPath = null;
            foreach ($this->archivers as $archiver) {
                if (!$archiver->isEnabled()) {
                    $this->logger->info('archiver skipped', ['kind' => $archiver->kind(), 'link' => $link->getId()]);
                    continue;
                }
                try {
                    $ext = match ($archiver->kind()) {
                        ArchiveAsset::KIND_SINGLEFILE => 'html',
                        ArchiveAsset::KIND_SCREENSHOT => 'png',
                        ArchiveAsset::KIND_PDF => 'pdf',
                        default => 'bin',
                    };
                    $out = \sprintf('%s/%s.%s', $baseDir, $archiver->kind(), $ext);
                    $archiver->archive($link->getUrl(), $out);
                    $size = filesize($out);
                    $relative = \sprintf('%d/%s.%s', (int) $link->getId(), $archiver->kind(), $ext);
                    $this->em->persist(new ArchiveAsset($link, $archiver->kind(), $relative, false === $size ? 0 : $size));
                    if (ArchiveAsset::KIND_PDF === $archiver->kind()) {
                        $pdfPath = $out;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('archiver failed', ['kind' => $archiver->kind(), 'link' => $link->getId(), 'err' => $e->getMessage()]);
                }
            }

            $this->applyPreviewImages($link, $baseDir, $pdfPath, $html);

            $link->setStatus(Link::STATUS_DONE);
            $link->setArchivedAt(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $link->setStatus(Link::STATUS_FAILED);
            $link->setLastError($e->getMessage());
            $this->logger->error('archive failed', ['link' => $link->getId(), 'err' => $e->getMessage()]);
        } finally {
            $this->em->flush();
        }
    }

    /**
     * Produces the card thumbnail (and, when possible, the screenshot asset)
     * without an extra network round-trip: if a PDF was captured and ImageMagick
     * can rasterise it, both images are derived from the PDF's first page.
     * Otherwise we fall back to downloading the page's og:image.
     */
    private function applyPreviewImages(Link $link, string $baseDir, ?string $pdfPath, string $html): void
    {
        if (null !== $pdfPath && $this->pdfRenderer->canRender()) {
            $id = (int) $link->getId();
            $shotOut = \sprintf('%s/%s.png', $baseDir, ArchiveAsset::KIND_SCREENSHOT);
            if ($this->pdfRenderer->renderPage($pdfPath, $shotOut, 1280)) {
                $size = filesize($shotOut);
                $this->em->persist(new ArchiveAsset(
                    $link,
                    ArchiveAsset::KIND_SCREENSHOT,
                    \sprintf('%d/%s.png', $id, ArchiveAsset::KIND_SCREENSHOT),
                    false === $size ? 0 : $size,
                ));

                $thumbOut = \sprintf('%s/preview.jpg', $baseDir);
                if ($this->pdfRenderer->renderPage($pdfPath, $thumbOut, 600)) {
                    $link->setPreviewImage(\sprintf('%d/preview.jpg', $id));
                }

                return;
            }
        }

        $preview = $this->preview->fetch($html, $link->getUrl(), (int) $link->getId());
        if (null !== $preview) {
            $link->setPreviewImage($preview);
        }
    }
}
