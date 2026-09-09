<?php

declare(strict_types=1);

namespace App\Service\Ai\Schema;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/** Phase-2 response: which bookmark ids belong in the chosen target sub-folder. */
final class LinkAssignment
{
    /**
     * @param list<int> $linkIds
     */
    public function __construct(
        #[Schema(description: 'IDs of bookmarks that belong in the target folder. Use only ids present in the provided list.')]
        public array $linkIds = [],
    ) {
    }
}
