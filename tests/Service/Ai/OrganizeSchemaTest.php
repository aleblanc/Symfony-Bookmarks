<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\Schema\CategoryProposal;
use App\Service\Ai\Schema\LinkAssignment;
use App\Service\Ai\Schema\ProposedCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class OrganizeSchemaTest extends KernelTestCase
{
    public function testCategoryProposalDeserializesNestedObjects(): void
    {
        self::bootKernel();
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('serializer');

        $json = '{"categories":[{"name":"Cuisine","description":"Recettes et plats","exampleTitles":["Tarte aux pommes","Poulet rôti"]}]}';
        $proposal = $serializer->deserialize($json, CategoryProposal::class, 'json');

        self::assertInstanceOf(CategoryProposal::class, $proposal);
        self::assertCount(1, $proposal->categories);
        self::assertInstanceOf(ProposedCategory::class, $proposal->categories[0]);
        self::assertSame('Cuisine', $proposal->categories[0]->name);
        self::assertSame(['Tarte aux pommes', 'Poulet rôti'], $proposal->categories[0]->exampleTitles);
    }

    public function testLinkAssignmentDeserializesIntList(): void
    {
        self::bootKernel();
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('serializer');

        $assignment = $serializer->deserialize('{"linkIds":[3,7,9]}', LinkAssignment::class, 'json');

        self::assertInstanceOf(LinkAssignment::class, $assignment);
        self::assertSame([3, 7, 9], $assignment->linkIds);
    }
}
