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
        #[Schema(description: '5 to 6 sub-folders that partition the bookmarks by topic.', minItems: 5, maxItems: 6)]
        public array $categories = [],
    ) {
    }
}
