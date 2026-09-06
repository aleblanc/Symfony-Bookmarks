<?php

declare(strict_types=1);

namespace App\Tests\Service\Archiver;

use App\Service\Archiver\PdfImageRenderer;
use PHPUnit\Framework\TestCase;

final class PdfImageRendererTest extends TestCase
{
    public function testFindReturnsNullWhenNoBinaryExists(): void
    {
        $renderer = new PdfImageRenderer('', ['/nonexistent/convert', '/also/missing/magick']);
        self::assertNull($renderer->find());
        self::assertFalse($renderer->canRender());
        self::assertFalse($renderer->renderPage('/tmp/whatever.pdf', '/tmp/out.png', 800));
    }

    public function testFindPicksFirstExistingExecutable(): void
    {
        $renderer = new PdfImageRenderer('', ['/nonexistent/convert', '/bin/sh']);
        self::assertSame('/bin/sh', $renderer->find());
    }

    public function testCanRenderIsFalseWhenBinaryIsNotImageMagick(): void
    {
        // /bin/sh exists but cannot rasterise a PDF: the probe must fail gracefully.
        $renderer = new PdfImageRenderer('', ['/bin/sh']);
        self::assertFalse($renderer->canRender());
    }

    public function testRenderPageFailsGracefullyWithoutRealImageMagick(): void
    {
        $out = sys_get_temp_dir().'/pdfimg-'.uniqid().'.png';
        $renderer = new PdfImageRenderer('', ['/bin/true']);
        self::assertFalse($renderer->renderPage('/tmp/missing.pdf', $out, 800));
        self::assertFileDoesNotExist($out);
    }
}
