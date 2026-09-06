<?php

declare(strict_types=1);

namespace App\Tests\Service\Archiver;

use App\Service\Archiver\PreviewImageFetcher;
use PHPUnit\Framework\TestCase;

final class PreviewImageFetcherTest extends TestCase
{
    private PreviewImageFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new PreviewImageFetcher(sys_get_temp_dir());
    }

    public function testExtractsOgImageRegardlessOfAttributeOrder(): void
    {
        $html = '<html><head><meta content="https://cdn.example/og.jpg" property="og:image"></head></html>';
        self::assertSame('https://cdn.example/og.jpg', $this->fetcher->extractImageUrl($html, 'https://example.com/page'));
    }

    public function testFallsBackToTwitterImage(): void
    {
        $html = '<meta name="twitter:image" content="https://cdn.example/tw.png">';
        self::assertSame('https://cdn.example/tw.png', $this->fetcher->extractImageUrl($html, 'https://example.com/'));
    }

    public function testPrefersOgImageOverTwitter(): void
    {
        $html = '<meta name="twitter:image" content="https://x/tw.png"><meta property="og:image" content="https://x/og.png">';
        self::assertSame('https://x/og.png', $this->fetcher->extractImageUrl($html, 'https://x/'));
    }

    public function testResolvesProtocolRelativeUrl(): void
    {
        $html = '<meta property="og:image" content="//cdn.example/og.jpg">';
        self::assertSame('https://cdn.example/og.jpg', $this->fetcher->extractImageUrl($html, 'https://example.com/a/b'));
    }

    public function testResolvesRootRelativeUrl(): void
    {
        $html = '<meta property="og:image" content="/img/og.jpg">';
        self::assertSame('https://example.com/img/og.jpg', $this->fetcher->extractImageUrl($html, 'https://example.com/a/b'));
    }

    public function testResolvesPathRelativeUrl(): void
    {
        $html = '<meta property="og:image" content="og.jpg">';
        self::assertSame('https://example.com/a/og.jpg', $this->fetcher->extractImageUrl($html, 'https://example.com/a/b'));
    }

    public function testReturnsNullWhenNoImageMeta(): void
    {
        self::assertNull($this->fetcher->extractImageUrl('<html><head><title>x</title></head></html>', 'https://example.com/'));
    }
}
