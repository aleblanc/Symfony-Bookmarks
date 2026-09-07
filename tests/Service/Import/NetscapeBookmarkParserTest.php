<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\NetscapeBookmarkParser;
use PHPUnit\Framework\TestCase;

final class NetscapeBookmarkParserTest extends TestCase
{
    private const HTML = <<<'HTML'
        <!DOCTYPE NETSCAPE-Bookmark-file-1>
        <DL><p>
            <DT><A HREF="https://root.com">Lien Racine</A>
            <DT><H3>Barre personnelle</H3>
            <DL><p>
                <DT><A HREF="https://a.com">Lien A</A>
                <DT><H3>Sous-dossier Dev</H3>
                <DL><p>
                    <DT><A HREF="https://b.com">Lien B</A>
                    <DT><H3>Sous-sous Foo</H3>
                    <DL><p>
                        <DT><A HREF="https://e.com">Lien E</A>
                    </DL><p>
                </DL><p>
                <DT><A HREF="https://c.com">Lien C</A>
            </DL><p>
            <DT><H3>Autre dossier</H3>
            <DL><p>
                <DT><A HREF="https://d.com">Lien D</A>
            </DL><p>
        </DL><p>
        HTML;

    public function testRootLinkHasEmptyPath(): void
    {
        self::assertSame([], $this->pathByUrl()['https://root.com']);
    }

    public function testLinkAfterSubfolderReturnsToParentFolder(): void
    {
        // The regression: "Lien C" comes after a subfolder but belongs to "Barre personnelle".
        self::assertSame(['Barre personnelle'], $this->pathByUrl()['https://c.com']);
    }

    public function testNestedFoldersProduceFullPaths(): void
    {
        $paths = $this->pathByUrl();
        self::assertSame(['Barre personnelle'], $paths['https://a.com']);
        self::assertSame(['Barre personnelle', 'Sous-dossier Dev'], $paths['https://b.com']);
        self::assertSame(['Barre personnelle', 'Sous-dossier Dev', 'Sous-sous Foo'], $paths['https://e.com']);
        self::assertSame(['Autre dossier'], $paths['https://d.com']);
    }

    public function testTitlesArePreserved(): void
    {
        $entries = (new NetscapeBookmarkParser())->parse(self::HTML, 'Imported');
        $byUrl = [];
        foreach ($entries as $e) {
            $byUrl[$e['url']] = $e['title'];
        }
        self::assertSame('Lien A', $byUrl['https://a.com']);
    }

    public function testInvalidUrlsAreSkipped(): void
    {
        $entries = (new NetscapeBookmarkParser())->parse(
            '<DL><p><DT><A HREF="not a url">Bad</A><DT><A HREF="https://ok.com">Ok</A></DL>',
            'Imported',
        );
        self::assertCount(1, $entries);
        self::assertSame('https://ok.com', $entries[0]['url']);
    }

    public function testFolderTreeStructureAndCounts(): void
    {
        $parser = new NetscapeBookmarkParser();
        $tree = $parser->folderTree($parser->parse(self::HTML, 'Imported'));

        self::assertSame(1, $tree['root_count']); // "Lien Racine"
        self::assertCount(2, $tree['nodes']); // "Autre dossier", "Barre personnelle" (natural sort)

        $names = array_map(static fn (array $n): string => $n['name'], $tree['nodes']);
        self::assertContains('Barre personnelle', $names);
        self::assertContains('Autre dossier', $names);

        $barre = array_values(array_filter($tree['nodes'], static fn (array $n): bool => 'Barre personnelle' === $n['name']))[0];
        self::assertSame(2, $barre['count']); // Lien A + Lien C
        self::assertSame('Sous-dossier Dev', $barre['children'][0]['name']);
        self::assertSame($parser->pathId(['Barre personnelle', 'Sous-dossier Dev']), $barre['children'][0]['id']);
    }

    /**
     * @return array<string, list<string>> url => folder path
     */
    private function pathByUrl(): array
    {
        $out = [];
        foreach ((new NetscapeBookmarkParser())->parse(self::HTML, 'Imported') as $e) {
            $out[$e['url']] = $e['folders'];
        }

        return $out;
    }
}
