<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Collection;
use App\Entity\Dashboard;
use App\Entity\Link;
use App\Service\Ai\FolderOrganizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FolderOrganizerApplyTest extends KernelTestCase
{
    public function testApplyCreatesSubfolderUnderParentAndMovesOnlySelectedLinks(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var FolderOrganizer $organizer */
        $organizer = self::getContainer()->get(FolderOrganizer::class);

        $dashboard = $em->getRepository(Dashboard::class)->findOneBy([]);
        self::assertNotNull($dashboard);

        $parent = new Collection('Apply-Test-Parent '.uniqid('', true), $dashboard);
        $em->persist($parent);
        $a = new Link('https://example.com/a', $parent);
        $b = new Link('https://example.com/b', $parent);
        $c = new Link('https://example.com/c', $parent);
        $em->persist($a);
        $em->persist($b);
        $em->persist($c);
        $em->flush();
        $aId = (int) $a->getId();
        $bId = (int) $b->getId();
        $cId = (int) $c->getId();

        $child = $organizer->applyCategory($parent, 'Cuisine', [$aId, $bId]);

        self::assertSame($parent, $child->getParent());
        self::assertSame($dashboard, $child->getDashboard());
        self::assertSame('Cuisine', $child->getName());

        $childId = (int) $child->getId();
        $em->clear();
        self::assertSame($childId, $em->find(Link::class, $aId)->getCollection()->getId());
        self::assertSame($childId, $em->find(Link::class, $bId)->getCollection()->getId());
        self::assertSame($parent->getId(), $em->find(Link::class, $cId)->getCollection()->getId());
    }
}
