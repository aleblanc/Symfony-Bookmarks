<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Dashboard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CollectionApiTest extends WebTestCase
{
    public function testMoveViaPutAndCycleGuard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $dashboard = $em->getRepository(Dashboard::class)->findOneBy([]) ?? new Dashboard('T');
        if (null === $dashboard->getId()) {
            $em->persist($dashboard);
        }
        $a = new Collection('A-'.uniqid(), $dashboard);
        $b = new Collection('B-'.uniqid(), $dashboard);
        $em->persist($a);
        $em->persist($b);
        $em->flush();
        $aId = (int) $a->getId();
        $bId = (int) $b->getId();

        // Move B under A.
        $client->request('PUT', '/api/v1/collections/'.$bId, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['parentId' => $aId]));
        self::assertResponseIsSuccessful();
        $em->clear();
        $movedB = $em->getRepository(Collection::class)->find($bId);
        self::assertNotNull($movedB);
        self::assertSame($aId, $movedB->getParent()?->getId());

        // Attempt a cycle: move A under B (now a descendant of A) — must be rejected.
        $client->request('PUT', '/api/v1/collections/'.$aId, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['parentId' => $bId]));
        self::assertResponseStatusCodeSame(400);
    }
}
