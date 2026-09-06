<?php

declare(strict_types=1);

namespace App\Service\Archiver;

use App\Entity\ArchiveAsset;
use App\Entity\Link;
use App\Service\Favicon\FaviconFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly iterable $archivers,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly FaviconFetcher $favicon,
        private readonly PreviewImageFetcher $preview,
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

            $preview = $this->preview->fetch($html, $link->getUrl(), (int) $link->getId());
            if (null !== $preview) {
                $link->setPreviewImage($preview);
            }

            $baseDir = \sprintf('%s/%d', $this->archiveDir, (int) $link->getId());
            if (!is_dir($baseDir)) {
                @mkdir($baseDir, 0o755, true);
            }

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
                } catch (\Throwable $e) {
                    $this->logger->warning('archiver failed', ['kind' => $archiver->kind(), 'link' => $link->getId(), 'err' => $e->getMessage()]);
                }
            }

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
}
