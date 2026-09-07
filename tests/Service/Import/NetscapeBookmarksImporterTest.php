<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Repository\CollectionRepository;
use App\Service\Import\NetscapeBookmarkParser;
use App\Service\Import\NetscapeBookmarksImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NetscapeBookmarksImporterTest extends KernelTestCase
{
    private const HTML = <<<'HTML'
        <!DOCTYPE NETSCAPE-Bookmark-file-1>
        <DL><p>
            <DT><A HREF="https://root.example">Root link</A>
            <DT><H3>Barre personnelle</H3>
            <DL><p>
                <DT><A HREF="https://a.example">Lien A</A>
                <DT><H3>Dev</H3>
                <DL><p><DT><A HREF="https://dev.example">Lien Dev</A></DL><p>
            </DL><p>
        </DL><p>
        HTML;

    public function testSelectingOnlySubfolderDoesNotCreateUncheckedParentOrRoot(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $importer = $container->get(NetscapeBookmarksImporter::class);
        $parser = $container->get(NetscapeBookmarkParser::class);
        \assert($em instanceof EntityManagerInterface);
        \assert($importer instanceof NetscapeBookmarksImporter);
        \assert($parser instanceof NetscapeBookmarkParser);

        $dashboard = new Dashboard('ImportTest-'.uniqid());
        $em->persist($dashboard);
        $em->flush();

        // Tick only "Dev" (path Barre personnelle / Dev), not "Barre personnelle", not root.
        $devId = $parser->pathId(['Barre personnelle', 'Dev']);
        $stats = $importer->import(self::HTML, $dashboard, [$devId]);

        self::assertSame(1, $stats['links']); // only "Lien Dev"

        $repo = $container->get(CollectionRepository::class);
        \assert($repo instanceof CollectionRepository);
        $collections = $repo->findBy(['dashboard' => $dashboard]);
        $names = array_map(static fn (Collection $c): string => $c->getName(), $collections);

        self::assertContains('Dev', $names);
        self::assertNotContains('Barre personnelle', $names, 'unchecked parent must not be created');
        self::assertNotContains('Imported', $names, 'unchecked root must not be created');

        $dev = $repo->findOneBy(['dashboard' => $dashboard, 'name' => 'Dev']);
        self::assertNotNull($dev);
        self::assertNull($dev->getParent(), 'Dev should sit at top level, not under an uncreated parent');
    }
}
