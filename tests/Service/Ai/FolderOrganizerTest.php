<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\FolderOrganizer;
use PHPUnit\Framework\TestCase;

final class FolderOrganizerTest extends TestCase
{
    public function testKeepKnownIdsDropsHallucinatedAndDuplicates(): void
    {
        self::assertSame([3, 7], FolderOrganizer::keepKnownIds([3, 7, 999, 7], [3, 7, 9]));
    }

    public function testKeepKnownIdsPreservesInputOrder(): void
    {
        self::assertSame([9, 3], FolderOrganizer::keepKnownIds([9, 3], [3, 7, 9]));
    }
}
