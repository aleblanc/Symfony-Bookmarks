<?php

declare(strict_types=1);

namespace App\Service\Ai\Schema;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/** One proposed sub-folder for a messy bookmark collection. */
final class ProposedCategory
{
    /**
     * @param list<string> $exampleTitles
     */
    public function __construct(
        #[Schema(description: 'Short sub-folder name, 1 to 3 words, in the same language as the bookmarks.', minLength: 2, maxLength: 40)]
        public string $name = '',
        #[Schema(description: 'One short sentence describing what belongs in this sub-folder.')]
        public string $description = '',
        #[Schema(description: '2 to 3 example bookmark titles, taken verbatim from the provided list.', minItems: 2, maxItems: 3)]
        public array $exampleTitles = [],
    ) {
    }
}
