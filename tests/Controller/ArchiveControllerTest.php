<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ArchiveAsset;
use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Entity\Link;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ArchiveControllerTest extends WebTestCase
{
    public function testServesAnArchivedAssetInline(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $archiveDir = $container->getParameter('kernel.project_dir').'/var/archives';
        $relative = 'test-'.uniqid().'/pdf.pdf';
        $full = $archiveDir.'/'.$relative;
        @mkdir(\dirname($full), 0o755, true);
        file_put_contents($full, '%PDF-1.4 fake');

        try {
            $dashboard = $em->getRepository(Dashboard::class)->findOneBy([]) ?? new Dashboard('T');
            if (null === $dashboard->getId()) {
                $em->persist($dashboard);
            }
            $collection = new Collection('AssetTest', $dashboard);
            $em->persist($collection);
            $link = new Link('https://asset-test.example', $collection);
            $em->persist($link);
            $asset = new ArchiveAsset($link, ArchiveAsset::KIND_PDF, $relative, filesize($full) ?: 0);
            $em->persist($asset);
            $em->flush();

            $client->request('GET', '/archives/'.$asset->getId());

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('inline', (string) $client->getResponse()->headers->get('Content-Disposition'));
        } finally {
            @unlink($full);
            @rmdir(\dirname($full));
        }
    }

    public function testUnknownAssetReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/archives/99999999');

        self::assertResponseStatusCodeSame(404);
    }
}
