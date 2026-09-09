<?php

declare(strict_types=1);

namespace App\Service\Ai\Schema;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/** Phase-1 response: the set of sub-folders proposed for a collection. */
final class CategoryProposal
{
    /**
     * @param list<ProposedCategory> $categories
     */
    public function __construct(
        #[Schema(description: 'Between 3 and 12 sub-folders that partition the bookmarks by topic.', minItems: 3, maxItems: 12)]
        public array $categories = [],
    ) {
    }
}
