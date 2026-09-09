# AI Folder Organizer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-click "🪄🤖 Organize with AI" action on a collection page that (phase 1) proposes sub-folders for the folder's bookmarks, then (phase 2) — once the user picks one proposed folder — asks the AI which links belong in it, and lets the user review and apply (create the sub-folder + move the selected links).

**Architecture:** Two dedicated LM Studio agents (`organizer_proposer`, `organizer_assigner`) declared in `ai.yaml`, each with its own system prompt and its own JSON output schema expressed as a PHP DTO annotated with `#[Schema]`. A `FolderOrganizer` service builds the compact bookmark list, injects the DTO-derived JSON schema into the user prompt, calls the agent with `response_format` (structured output), validates the returned data, and applies the change in one Doctrine transaction. Three POST-only controller routes drive the multi-step UI (propose → assign → apply). All AI work is synchronous and on-demand (no cron, no Messenger — consistent with the project).

**Tech Stack:** PHP 8.5, Symfony, Doctrine ORM v3, `symfony/ai-agent` + `symfony/ai-platform` + `symfony/ai-bundle` (LM Studio bridge), Twig, SQLite, PHPUnit, PHPStan level 8.

**Spec:** This document (design agreed in conversation 2026-09-09). Implements ROADMAP item #2 ("Section « À trier » + catégorisation IA"), scoped here to *bulk-reorganizing one existing folder*.

## Revision 2026-09-09 (during execution)

Phase 2 was changed from a **separate page** to a **single-page** flow, on user
feedback: if the assigner returns too few links, the user must be able to try
another proposed folder **without re-running the expensive phase-1 analysis**.

- `propose` renders `collections/organize.html.twig` with the phase-1 categories
  and a serialized copy in a hidden field `proposal` (JSON).
- Each category's form posts to `assign` carrying that `proposal` JSON + the
  picked `name`/`description`. `assign` decodes the proposal (no phase-1 re-run),
  runs only phase 2, and re-renders the **same** template: proposals on top
  (still clickable), the picked folder's links below, with a count.
- The separate `organize_assign.html.twig` template was dropped.
- `FolderOrganizer` is unchanged; `CollectionController` uses repositories
  (`LinkRepository::findForCollection`, `CollectionRepository::findChildren`)
  because `Collection` has no `getLinks()`/`getChildren()` accessors.
- Tasks 3–7 were committed together (not per-task) so PHPStan stayed green on
  every commit, per the project's "green on every commit" rule.

## Global Constraints

- `declare(strict_types=1);` on every PHP file.
- `DateTimeImmutable`, never `DateTime`.
- **All queries live in a repository method**, never in a controller.
- Entity collection getters return `array` (via `toArray()`), never Doctrine `Collection`.
- Autowiring via constructor injection; `readonly` on injected service properties. **Named agents cannot be autowired by type** — they MUST be bound explicitly in `services.yaml` (mirror the existing `$taggerAgent` binding).
- Controllers are `final`, extend `AbstractController`, use attribute routing (`#[Route]`).
- Repositories are `final`, extend `ServiceEntityRepository`, with `@extends` PHPDoc.
- Templates: `snake_case`, partials prefixed `_`.
- PHPStan level 8 must stay green (no baseline). `composer cs-check` must pass (`@Symfony:risky` + `declare_strict_types` + `strict_param`).
- The AI model name sent to LM Studio must exactly match a key under `ai.model.lmstudio` in `ai.yaml` (currently `qwen3-vl-8b-instruct`). Reuse `%env(APP_AI_TAG_MODEL)%` — do NOT introduce a new model key.
- These new routes call a remote LLM and MUST be POST-only so the GET-based `tests/SmokeTest.php` never triggers a network call. Do **not** add them to the smoke provider.
- Gate the UI entry point behind `APP_AI_ENABLED`: no button when AI is off.

---

## File Structure

**Create:**
- `src/Service/Ai/Schema/ProposedCategory.php` — DTO: one proposed sub-folder (`name`, `description`, `exampleTitles`).
- `src/Service/Ai/Schema/CategoryProposal.php` — DTO: phase-1 response (`categories: list<ProposedCategory>`).
- `src/Service/Ai/Schema/LinkAssignment.php` — DTO: phase-2 response (`linkIds: list<int>`).
- `src/Service/Ai/FolderOrganizer.php` — orchestrates both agent calls, validates IDs, applies the move.
- `src/Controller/Web/CollectionOrganizeController.php` — 3 POST routes (propose / assign / apply).
- `templates/collections/organize.html.twig` — phase-1 proposal review page.
- `templates/collections/organize_assign.html.twig` — phase-2 link-selection review page.
- `tests/Service/Ai/OrganizeSchemaTest.php` — DTO hydration tests.
- `tests/Service/Ai/FolderOrganizerTest.php` — pure-helper + apply (DB) tests.

**Modify:**
- `config/packages/ai.yaml` — add `organizer_proposer` + `organizer_assigner` agents.
- `config/services.yaml` — bind the two new agents into `FolderOrganizer`; wire the JSON-schema Factory; add `$aiEnabled` bind.
- `src/Controller/Web/CollectionController.php:56-61` — pass `ai_enabled` to the show template.
- `templates/collections/show.html.twig:58-72` — add the 🪄🤖 button.
- `translations/messages.fr.yaml` / `messages.en.yaml` — new `collection.organize_*` keys.
- `docs/ROADMAP.md` — mark item #2 as partially implemented.
- `README.md` — one feature bullet.

**Reference (read, do not edit):**
- `src/Service/Ai/AutoTagger.php` — the agent-call pattern to mirror (`AgentInterface::call(new MessageBag(Message::ofUser($prompt)))->getContent()`).
- `src/Entity/Link.php` (`__construct(string $url, Collection $collection)`, `setCollection`, `getName`, `getUrl`, `getId`).
- `src/Entity/Collection.php` (`__construct(string $name, Dashboard $dashboard)`, `getDashboard`, `getParent`, `setParent`, `getVault`, `setVault`, `setPosition`).
- `src/Repository/CollectionRepository.php` (`findChildren`).
- `src/Repository/LinkRepository.php` (`findForCollection`).

---

### Task 1: Output-schema DTOs

**Files:**
- Create: `src/Service/Ai/Schema/ProposedCategory.php`
- Create: `src/Service/Ai/Schema/CategoryProposal.php`
- Create: `src/Service/Ai/Schema/LinkAssignment.php`
- Test: `tests/Service/Ai/OrganizeSchemaTest.php`

**Interfaces:**
- Produces:
  - `App\Service\Ai\Schema\ProposedCategory` with public promoted props `string $name`, `string $description`, `list<string> $exampleTitles`.
  - `App\Service\Ai\Schema\CategoryProposal` with public promoted prop `list<ProposedCategory> $categories`.
  - `App\Service\Ai\Schema\LinkAssignment` with public promoted prop `list<int> $linkIds`.
- These class names are passed as `response_format` in Task 3/4 and must deserialize from JSON via the Symfony serializer (nested typed arrays via PHPDoc).

- [ ] **Step 1: Write the failing test**

`tests/Service/Ai/OrganizeSchemaTest.php`:
```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `APP_ENV=test vendor/bin/phpunit tests/Service/Ai/OrganizeSchemaTest.php`
Expected: FAIL — `Class "App\Service\Ai\Schema\CategoryProposal" not found`.

- [ ] **Step 3: Create `ProposedCategory`**

`src/Service/Ai/Schema/ProposedCategory.php`:
```php
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
```

- [ ] **Step 4: Create `CategoryProposal`**

`src/Service/Ai/Schema/CategoryProposal.php`:
```php
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
```

- [ ] **Step 5: Create `LinkAssignment`**

`src/Service/Ai/Schema/LinkAssignment.php`:
```php
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
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `APP_ENV=test vendor/bin/phpunit tests/Service/Ai/OrganizeSchemaTest.php`
Expected: PASS (2 tests). If nested hydration fails, confirm the `@param list<...>` PHPDoc is present — the serializer's property-info reads it to type the array.

- [ ] **Step 7: Run PHPStan**

Run: `vendor/bin/phpstan analyse --memory-limit=512M src/Service/Ai/Schema`
Expected: `[OK] No errors`.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Ai/Schema tests/Service/Ai/OrganizeSchemaTest.php
git commit -m "feat(ai): add folder-organizer output schema DTOs"
```

---

### Task 2: Declare the two organizer agents

**Files:**
- Modify: `config/packages/ai.yaml` (under `ai.agent`)

**Interfaces:**
- Produces two container services: `ai.agent.organizer_proposer` and `ai.agent.organizer_assigner` (consumed by `FolderOrganizer` in Task 3, wired in Task 3's `services.yaml` step).

- [ ] **Step 1: Add the agents to `ai.yaml`**

Append under the existing `ai.agent:` map (sibling of `tagger`/`summarizer`), reusing the already-registered `qwen3-vl-8b-instruct` model via `APP_AI_TAG_MODEL`:
```yaml
        organizer_proposer:
            platform: 'ai.platform.lmstudio'
            model: '%env(APP_AI_TAG_MODEL)%'
            prompt: |
                You reorganize a messy bookmark folder into coherent sub-folders.
                You receive a numbered list of bookmarks ("#<id> <title>").
                Propose between 3 and 12 sub-folders that partition them by topic.
                For each sub-folder give: a short name (same language as the titles),
                a one-sentence description, and 2 to 3 example titles taken verbatim
                from the list. Every bookmark should plausibly fit one sub-folder.
                Answer strictly as JSON matching the provided schema. No prose, no code fences.

        organizer_assigner:
            platform: 'ai.platform.lmstudio'
            model: '%env(APP_AI_TAG_MODEL)%'
            prompt: |
                You assign bookmarks to a single target sub-folder.
                You receive the target folder (name + description) and a numbered list
                of bookmarks ("#<id> <title>"). Return the ids of the bookmarks that
                belong in that folder. Use only ids that appear in the list; omit the rest.
                Answer strictly as JSON matching the provided schema. No prose, no code fences.
```

- [ ] **Step 2: Verify the agents are registered**

Run: `php bin/console debug:container ai.agent.organizer_proposer`
Expected: a service definition is printed (not "no services found"). Repeat for `ai.agent.organizer_assigner`.

- [ ] **Step 3: Commit**

```bash
git add config/packages/ai.yaml
git commit -m "feat(ai): declare organizer_proposer and organizer_assigner agents"
```

---

### Task 3: `FolderOrganizer` — compact list, schema-in-prompt, phase-1 call

**Files:**
- Create: `src/Service/Ai/FolderOrganizer.php`
- Modify: `config/services.yaml`
- Test: `tests/Service/Ai/FolderOrganizerTest.php`

**Interfaces:**
- Consumes: `ai.agent.organizer_proposer`, `ai.agent.organizer_assigner` (Task 2); `Symfony\AI\Platform\Contract\JsonSchema\Factory` (schema builder); `App\Repository\LinkRepository`; `Doctrine\ORM\EntityManagerInterface`; `Psr\Log\LoggerInterface $aiLogger`.
- Produces (used by the controller in Task 6):
  - `public function proposeCategories(Collection $collection): CategoryProposal`
  - `public function buildBookmarkList(Collection $collection): array` returning `array{lines: string, ids: list<int>}` (public for testing; pure).
  - `public static function keepKnownIds(array $returned, array $known): array` returning `list<int>` (pure).

- [ ] **Step 1: Verify the JSON-schema Factory service id**

Run: `php bin/console debug:container --show-hidden 2>/dev/null | grep -i json_schema` and `grep -rn "JsonSchema\\\\Factory" vendor/symfony/ai-bundle/`
Expected: find the service id for `Symfony\AI\Platform\Contract\JsonSchema\Factory`. Note whether it is registered under the FQCN or an alias. Use whatever id it reports in the `services.yaml` binding in Step 5. (The class' `buildProperties(string $className): ?array` turns a DTO into a JSON-schema array.)

- [ ] **Step 2: Write the failing test (pure helpers)**

`tests/Service/Ai/FolderOrganizerTest.php`:
```php
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

    public function testKeepKnownIdsPreservesKnownOrderOfInput(): void
    {
        self::assertSame([9, 3], FolderOrganizer::keepKnownIds([9, 3], [3, 7, 9]));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `APP_ENV=test vendor/bin/phpunit tests/Service/Ai/FolderOrganizerTest.php`
Expected: FAIL — `Class "App\Service\Ai\FolderOrganizer" not found`.

- [ ] **Step 4: Create `FolderOrganizer` (list + schema-in-prompt + phase 1 + keepKnownIds)**

`src/Service/Ai/FolderOrganizer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Collection;
use App\Entity\Link;
use App\Service\Ai\Schema\CategoryProposal;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Factory as JsonSchemaFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class FolderOrganizer
{
    public function __construct(
        private readonly AgentInterface $proposerAgent,
        private readonly AgentInterface $assignerAgent,
        private readonly JsonSchemaFactory $schemaFactory,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $aiLogger,
    ) {
    }

    /**
     * Compact, token-cheap representation of a collection's links for the LLM.
     *
     * @return array{lines: string, ids: list<int>}
     */
    public function buildBookmarkList(Collection $collection): array
    {
        $lines = [];
        $ids = [];
        foreach ($collection->getLinks() as $link) {
            /** @var Link $link */
            $id = (int) $link->getId();
            $title = trim((string) ($link->getName() ?? '')) ?: $link->getUrl();
            $lines[] = '#'.$id.' '.$title;
            $ids[] = $id;
        }

        return ['lines' => implode("\n", $lines), 'ids' => $ids];
    }

    /** Phase 1: ask the LLM to propose sub-folders for this collection. */
    public function proposeCategories(Collection $collection): CategoryProposal
    {
        $list = $this->buildBookmarkList($collection);
        $prompt = "Bookmarks to organize:\n".$list['lines']
            ."\n\nRespond with JSON matching this schema:\n"
            .json_encode($this->schemaFactory->buildProperties(CategoryProposal::class), \JSON_THROW_ON_ERROR);

        $result = $this->proposerAgent
            ->call(new MessageBag(Message::ofUser($prompt)), ['response_format' => CategoryProposal::class])
            ->getContent();

        if (!$result instanceof CategoryProposal) {
            $this->aiLogger->warning('organizer: proposer returned unexpected content', ['type' => get_debug_type($result)]);

            return new CategoryProposal([]);
        }

        return $result;
    }

    /**
     * Keep only ids that exist in $known, de-duplicated, in input order.
     *
     * @param list<int> $returned
     * @param list<int> $known
     *
     * @return list<int>
     */
    public static function keepKnownIds(array $returned, array $known): array
    {
        $knownSet = array_fill_keys($known, true);
        $seen = [];
        $out = [];
        foreach ($returned as $id) {
            if (isset($knownSet[$id]) && !isset($seen[$id])) {
                $seen[$id] = true;
                $out[] = $id;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 5: Wire the service in `services.yaml`**

Add under `services:` (use the Factory id confirmed in Step 1 — shown here as the FQCN):
```yaml
    App\Service\Ai\FolderOrganizer:
        arguments:
            $proposerAgent: '@ai.agent.organizer_proposer'
            $assignerAgent: '@ai.agent.organizer_assigner'
            $schemaFactory: '@Symfony\AI\Platform\Contract\JsonSchema\Factory'
            $aiLogger: '@monolog.logger.ai'
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `APP_ENV=test vendor/bin/phpunit tests/Service/Ai/FolderOrganizerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Verify the container compiles and PHPStan is green**

Run: `php bin/console debug:container App\\Service\\Ai\\FolderOrganizer` then `vendor/bin/phpstan analyse --memory-limit=512M src/Service/Ai`
Expected: service prints without error; `[OK] No errors`. (If PHPStan complains that `getLinks()` returns `array` — it does per project convention — the `/** @var Link $link */` keeps the loop typed.)

- [ ] **Step 8: Commit**

```bash
git add src/Service/Ai/FolderOrganizer.php config/services.yaml tests/Service/Ai/FolderOrganizerTest.php
git commit -m "feat(ai): FolderOrganizer phase-1 proposal + id validation"
```

---

### Task 4: `FolderOrganizer::assignLinks` — phase-2 call

**Files:**
- Modify: `src/Service/Ai/FolderOrganizer.php`
- Test: `tests/Service/Ai/FolderOrganizerTest.php` (extend)

**Interfaces:**
- Produces: `public function assignLinks(Collection $collection, string $categoryName, string $categoryDescription): array` returning `list<int>` — validated link ids (subset of the collection's links).

- [ ] **Step 1: Add the method**

Insert into `FolderOrganizer` (after `proposeCategories`), reusing the assigner agent and `keepKnownIds`:
```php
    /**
     * Phase 2: ask the LLM which of the collection's links belong in the named target.
     *
     * @return list<int> validated link ids (guaranteed subset of the collection)
     */
    public function assignLinks(Collection $collection, string $categoryName, string $categoryDescription): array
    {
        $list = $this->buildBookmarkList($collection);
        $prompt = 'Target folder: '.$categoryName."\nDescription: ".$categoryDescription
            ."\n\nBookmarks:\n".$list['lines']
            ."\n\nRespond with JSON matching this schema:\n"
            .json_encode($this->schemaFactory->buildProperties(LinkAssignment::class), \JSON_THROW_ON_ERROR);

        $result = $this->assignerAgent
            ->call(new MessageBag(Message::ofUser($prompt)), ['response_format' => LinkAssignment::class])
            ->getContent();

        if (!$result instanceof LinkAssignment) {
            $this->aiLogger->warning('organizer: assigner returned unexpected content', ['type' => get_debug_type($result)]);

            return [];
        }

        return self::keepKnownIds(array_map('intval', $result->linkIds), $list['ids']);
    }
```
Add the import at the top: `use App\Service\Ai\Schema\LinkAssignment;`.

- [ ] **Step 2: (No new unit test needed — orchestration is thin.)**

The AI-facing orchestration cannot be unit-tested without stubbing the `Execution` return type of `AgentInterface::call()` (a concrete class in the vendor). The deterministic risk — dropping hallucinated ids — is already covered by `keepKnownIds` tests in Task 3, which `assignLinks` delegates to. Leave a note in the method that end-to-end behaviour is verified manually in Task 8's smoke run against LM Studio.

- [ ] **Step 3: Run PHPStan + full test suite**

Run: `vendor/bin/phpstan analyse --memory-limit=512M src/Service/Ai && APP_ENV=test vendor/bin/phpunit tests/Service/Ai`
Expected: `[OK] No errors`; all tests PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Ai/FolderOrganizer.php
git commit -m "feat(ai): FolderOrganizer phase-2 link assignment"
```

---

### Task 5: `FolderOrganizer::applyCategory` — create sub-folder + move links

**Files:**
- Modify: `src/Service/Ai/FolderOrganizer.php`
- Modify: `src/Repository/CollectionRepository.php` (only if `findChildren` count is needed — it exists; reuse it)
- Test: `tests/Service/Ai/FolderOrganizerApplyTest.php` (new; needs the test DB)

**Interfaces:**
- Produces: `public function applyCategory(Collection $parent, string $name, array $linkIds): Collection` — creates a child collection under `$parent` (same dashboard, inheriting `$parent`'s vault), moves the given (validated) links into it, flushes once, returns the new child.

- [ ] **Step 1: Write the failing DB test**

`tests/Service/Ai/FolderOrganizerApplyTest.php`:
```php
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

        $child = $organizer->applyCategory($parent, 'Cuisine', [(int) $a->getId(), (int) $b->getId()]);

        self::assertSame($parent, $child->getParent());
        self::assertSame($dashboard, $child->getDashboard());
        self::assertSame('Cuisine', $child->getName());

        $em->clear();
        self::assertSame($child->getId(), $em->find(Link::class, $a->getId())->getCollection()->getId());
        self::assertSame($child->getId(), $em->find(Link::class, $b->getId())->getCollection()->getId());
        self::assertSame($parent->getId(), $em->find(Link::class, $c->getId())->getCollection()->getId());
    }
}
```

- [ ] **Step 2: Ensure the test DB exists, then run to verify it fails**

Run:
```bash
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test vendor/bin/phpunit tests/Service/Ai/FolderOrganizerApplyTest.php
```
Expected: FAIL — `Call to undefined method App\Service\Ai\FolderOrganizer::applyCategory()`.

- [ ] **Step 3: Add `applyCategory`**

Insert into `FolderOrganizer`:
```php
    /**
     * Create a sub-folder under $parent and move the given links into it, atomically.
     * The child inherits $parent's vault so encrypted links stay in the same vault.
     *
     * @param list<int> $linkIds
     */
    public function applyCategory(Collection $parent, string $name, array $linkIds): Collection
    {
        $child = new Collection($name, $parent->getDashboard());
        $child->setParent($parent);
        $child->setVault($parent->getVault());
        $child->setPosition(\count($parent->getChildren()));

        $this->em->persist($child);

        $known = $this->buildBookmarkList($parent)['ids'];
        $move = array_fill_keys(self::keepKnownIds(array_map('intval', $linkIds), $known), true);
        foreach ($parent->getLinks() as $link) {
            /** @var Link $link */
            if (isset($move[(int) $link->getId()])) {
                $link->setCollection($child);
            }
        }

        $this->em->flush();

        return $child;
    }
```
Note: if `Collection::getChildren()` does not exist, use `$this->collections->findChildren($parent)` instead — inject `CollectionRepository $collections` and count that. Verify with `grep -n "getChildren\|findChildren" src/Entity/Collection.php src/Repository/CollectionRepository.php` and pick whichever exists; `findChildren` is confirmed to exist.

- [ ] **Step 4: Run the test to verify it passes**

Run: `APP_ENV=test vendor/bin/phpunit tests/Service/Ai/FolderOrganizerApplyTest.php`
Expected: PASS.

- [ ] **Step 5: PHPStan**

Run: `vendor/bin/phpstan analyse --memory-limit=512M src/Service/Ai`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Ai/FolderOrganizer.php tests/Service/Ai/FolderOrganizerApplyTest.php
git commit -m "feat(ai): apply proposed sub-folder — create + move links atomically"
```

---

### Task 6: Controller — propose / assign / apply routes + templates

**Files:**
- Create: `src/Controller/Web/CollectionOrganizeController.php`
- Create: `templates/collections/organize.html.twig`
- Create: `templates/collections/organize_assign.html.twig`

**Interfaces:**
- Consumes: `FolderOrganizer` (Tasks 3–5), `CollectionRepository`.
- Routes (all POST): `collections_organize` (`/collections/{id}/organize`), `collections_organize_assign` (`/collections/{id}/organize/assign`), `collections_organize_apply` (`/collections/{id}/organize/apply`).

- [ ] **Step 1: Create the controller**

`src/Controller/Web/CollectionOrganizeController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\CollectionRepository;
use App\Service\Ai\FolderOrganizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CollectionOrganizeController extends AbstractController
{
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly FolderOrganizer $organizer,
    ) {
    }

    #[Route('/collections/{id}/organize', name: 'collections_organize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function propose(int $id): Response
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();

        try {
            $proposal = $this->organizer->proposeCategories($collection);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'collection.organize_failed');

            return $this->redirectToRoute('collections_show', ['id' => $id]);
        }

        return $this->render('collections/organize.html.twig', [
            'collection' => $collection,
            'categories' => $proposal->categories,
        ]);
    }

    #[Route('/collections/{id}/organize/assign', name: 'collections_organize_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assign(int $id, Request $request): Response
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));
        if ('' === $name) {
            return $this->redirectToRoute('collections_show', ['id' => $id]);
        }

        try {
            $ids = $this->organizer->assignLinks($collection, $name, $description);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'collection.organize_failed');

            return $this->redirectToRoute('collections_show', ['id' => $id]);
        }

        $idSet = array_fill_keys($ids, true);
        $selected = [];
        foreach ($collection->getLinks() as $link) {
            if (isset($idSet[(int) $link->getId()])) {
                $selected[] = $link;
            }
        }

        return $this->render('collections/organize_assign.html.twig', [
            'collection' => $collection,
            'name' => $name,
            'links' => $selected,
        ]);
    }

    #[Route('/collections/{id}/organize/apply', name: 'collections_organize_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(int $id, Request $request): Response
    {
        $collection = $this->collections->find($id) ?? throw $this->createNotFoundException();
        $name = trim((string) $request->request->get('name', ''));
        /** @var list<int> $linkIds */
        $linkIds = array_map('intval', (array) $request->request->all('link_ids'));

        if ('' === $name || [] === $linkIds) {
            return $this->redirectToRoute('collections_show', ['id' => $id]);
        }

        $child = $this->organizer->applyCategory($collection, $name, $linkIds);

        return $this->redirectToRoute('collections_show', ['id' => $child->getId()]);
    }
}
```

- [ ] **Step 2: Create `templates/collections/organize.html.twig`**

```twig
{% extends 'base.html.twig' %}
{% block title %}🪄🤖 {{ 'collection.organize_title'|trans }} — {{ collection.name }}{% endblock %}
{% block body %}
    <header class="top"><h1>🪄🤖 {{ 'collection.organize_title'|trans }}</h1></header>
    <p class="muted">{{ 'collection.organize_intro'|trans({'%folder%': collection.name}) }}</p>

    {% if categories is empty %}
        <p class="muted">{{ 'collection.organize_empty'|trans }}</p>
        <a class="btn btn-sm" href="{{ path('collections_show', {id: collection.id}) }}">{{ 'common.back'|trans }}</a>
    {% else %}
        <div class="cards">
            {% for cat in categories %}
                <article class="card">
                    <h3 style="margin:0 0 6px;">{{ cat.name }}</h3>
                    {% if cat.description %}<p class="muted">{{ cat.description }}</p>{% endif %}
                    {% if cat.exampleTitles is not empty %}
                        <ul class="muted" style="margin:6px 0 0; padding-left:18px;">
                            {% for ex in cat.exampleTitles %}<li>{{ ex }}</li>{% endfor %}
                        </ul>
                    {% endif %}
                    <form class="row" method="post" action="{{ path('collections_organize_assign', {id: collection.id}) }}" style="margin-top:12px;">
                        <input type="hidden" name="name" value="{{ cat.name }}">
                        <input type="hidden" name="description" value="{{ cat.description }}">
                        <button class="btn btn-sm btn-primary" type="submit">{{ 'collection.organize_pick'|trans }}</button>
                    </form>
                </article>
            {% endfor %}
        </div>
    {% endif %}
{% endblock %}
```

- [ ] **Step 3: Create `templates/collections/organize_assign.html.twig`**

```twig
{% extends 'base.html.twig' %}
{% block title %}🪄🤖 {{ name }} — {{ collection.name }}{% endblock %}
{% block body %}
    <header class="top"><h1>🪄🤖 {{ 'collection.organize_review'|trans({'%folder%': name}) }}</h1></header>

    {% if links is empty %}
        <p class="muted">{{ 'collection.organize_empty'|trans }}</p>
        <a class="btn btn-sm" href="{{ path('collections_show', {id: collection.id}) }}">{{ 'common.back'|trans }}</a>
    {% else %}
        <form method="post" action="{{ path('collections_organize_apply', {id: collection.id}) }}">
            <label class="field"><span>{{ 'collection.organize_folder_name'|trans }}</span>
                <input class="input" type="text" name="name" value="{{ name }}" required style="max-width:320px;"></label>
            <div class="cards" style="margin-top:12px;">
                {% for link in links %}
                    <label class="card field-check" style="display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="link_ids[]" value="{{ link.id }}" checked>
                        <span>{{ link.name ?: link.url }}</span>
                    </label>
                {% endfor %}
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:12px;">
                {{ 'collection.organize_apply'|trans }}
            </button>
        </form>
    {% endif %}
{% endblock %}
```

- [ ] **Step 4: Verify routes are registered**

Run: `php bin/console debug:router | grep collections_organize`
Expected: three POST routes listed.

- [ ] **Step 5: PHPStan**

Run: `vendor/bin/phpstan analyse --memory-limit=512M src/Controller/Web/CollectionOrganizeController.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Web/CollectionOrganizeController.php templates/collections/organize.html.twig templates/collections/organize_assign.html.twig
git commit -m "feat(collections): AI organize flow — propose/assign/apply routes and views"
```

---

### Task 7: The 🪄🤖 button, translations, and AI gating

**Files:**
- Modify: `config/services.yaml` (add `$aiEnabled` bind)
- Modify: `src/Controller/Web/CollectionController.php:16-21` (inject) and `:56-61` (pass `ai_enabled`)
- Modify: `templates/collections/show.html.twig:58-72` (button)
- Modify: `translations/messages.fr.yaml`, `translations/messages.en.yaml`

**Interfaces:**
- Consumes: `ai_enabled` (bool) in the show template.

- [ ] **Step 1: Add the `$aiEnabled` bind in `services.yaml`**

Under `services._defaults.bind:` (create the `bind:` key if absent):
```yaml
        bool $aiEnabled: '%env(bool:APP_AI_ENABLED)%'
```

- [ ] **Step 2: Inject and pass the flag from `CollectionController`**

In `CollectionController::__construct`, add a constructor argument:
```php
        private readonly bool $aiEnabled = false,
```
In `show()`'s `render(...)` array, add:
```php
            'ai_enabled' => $this->aiEnabled,
```

- [ ] **Step 3: Add the button to `show.html.twig`**

Inside the existing `<div class="row" style="margin-top:24px; gap:16px;">` action region (with merge/delete), add — only when AI is on and the folder has links:
```twig
        {% if ai_enabled and links is not empty %}
            <form class="inline" method="post" action="{{ path('collections_organize', {id: collection.id}) }}">
                <button class="btn btn-sm" type="submit" title="{{ 'collection.organize'|trans }}">🪄🤖 {{ 'collection.organize'|trans }}</button>
            </form>
        {% endif %}
```

- [ ] **Step 4: Add translation keys (FR)**

In `translations/messages.fr.yaml` under the `collection:` map:
```yaml
    organize: 'Ranger avec l''IA'
    organize_title: 'Ranger le dossier avec l''IA'
    organize_intro: 'Sous-dossiers proposés pour « %folder% ». Choisis-en un à créer.'
    organize_pick: 'Choisir ce dossier'
    organize_review: 'Liens proposés pour « %folder% »'
    organize_folder_name: 'Nom du dossier à créer'
    organize_apply: 'Créer le dossier et y déplacer les liens'
    organize_empty: 'Aucune suggestion.'
    organize_failed: 'Le service IA est indisponible. Réessaie plus tard.'
```

- [ ] **Step 5: Add translation keys (EN)**

In `translations/messages.en.yaml` under the `collection:` map:
```yaml
    organize: 'Organize with AI'
    organize_title: 'Organize the folder with AI'
    organize_intro: 'Suggested sub-folders for "%folder%". Pick one to create.'
    organize_pick: 'Choose this folder'
    organize_review: 'Links suggested for "%folder%"'
    organize_folder_name: 'Name of the folder to create'
    organize_apply: 'Create the folder and move the links'
    organize_empty: 'No suggestions.'
    organize_failed: 'The AI service is unavailable. Try again later.'
```
Also confirm `common.back` exists in both files; if not, add `back: 'Retour'` / `back: 'Back'`.

- [ ] **Step 6: Run the smoke test (button renders, no AI call on GET)**

Run: `APP_ENV=test vendor/bin/phpunit --filter Smoke`
Expected: PASS (9+ tests). The GET collection page renders whether or not `ai_enabled` is set; the button is a POST form, so no network call fires.

- [ ] **Step 7: PHPStan + cs-check**

Run: `vendor/bin/phpstan analyse --memory-limit=512M && composer cs-check`
Expected: `[OK] No errors`; cs-check clean.

- [ ] **Step 8: Commit**

```bash
git add config/services.yaml src/Controller/Web/CollectionController.php templates/collections/show.html.twig translations/messages.fr.yaml translations/messages.en.yaml
git commit -m "feat(collections): add AI organize button (gated by APP_AI_ENABLED) + translations"
```

---

### Task 8: Manual end-to-end verification + docs

**Files:**
- Modify: `docs/ROADMAP.md`
- Modify: `README.md`

- [ ] **Step 1: Manual E2E against LM Studio**

With `APP_AI_ENABLED=true` and LM Studio reachable, open a collection with 20+ links, click 🪄🤖. Confirm: phase-1 shows 3–12 folders with examples; picking one shows a pre-checked link list; applying creates the sub-folder under the current folder and moves exactly the checked links. Uncheck some and re-apply to confirm partial moves. Verify a vault-protected folder still works when unlocked.

- [ ] **Step 2: Mark ROADMAP #2 partially done**

In `docs/ROADMAP.md` under "## 2. Section « À trier » + catégorisation IA", add a note:
```markdown
> **Partiellement implémenté (2026-09-09)** : rangement en masse d'un dossier
> existant via le bouton 🪄🤖 (agents `organizer_proposer` / `organizer_assigner`,
> service `FolderOrganizer`). Reste à faire : la file « À trier » automatique pour
> les liens sans collection.
```

- [ ] **Step 3: Add a README feature bullet**

In `README.md` under `## Features`, add:
```markdown
- **AI folder organizer**: on a collection, "🪄🤖 Organize with AI" proposes sub-folders (with example links), then — for a chosen folder — lists which links to move; you review and apply. Uses the same remote LM Studio stack, gated by `APP_AI_ENABLED`.
```

- [ ] **Step 4: Commit**

```bash
git add docs/ROADMAP.md README.md
git commit -m "docs: document the AI folder organizer feature"
```

---

## Self-Review

**Spec coverage:**
- Phase 1 proposes sub-categories, forced JSON → Task 1 (`CategoryProposal` DTO) + Task 2 (`organizer_proposer` prompt) + Task 3 (`proposeCategories`, `response_format`). ✅
- 2–3 examples per category → `ProposedCategory::exampleTitles` (`#[Schema(minItems:2,maxItems:3)]`) + shown in `organize.html.twig`. ✅
- User picks one folder → phase-1 template's per-category form → `collections_organize_assign`. ✅
- Phase 2 asks AI which links, JSON list → Task 1 (`LinkAssignment`) + Task 2 (`organizer_assigner`) + Task 4 (`assignLinks`). ✅
- UI proposes the link list to review → `organize_assign.html.twig` (pre-checked checkboxes). ✅
- Apply = create folder + move links → Task 5 (`applyCategory`, transactional) + Task 6 apply route. ✅
- DTO per schema with `#[Schema]` on each constructor argument → Task 1. ✅
- Schema passed into the prompt → Task 3/4 embed `schemaFactory->buildProperties(...)` JSON. ✅
- Two prompts / two schemas → two agents + two DTOs. ✅
- Button in the folder view, magic-wand + robot picto → Task 7 (`🪄🤖` in `show.html.twig`). ✅

**Placeholder scan:** No "TBD"/"handle edge cases" left. The one runtime unknown — the JSON-schema Factory service id — is resolved by an explicit verification step (Task 3 Step 1) before it is used, not left as a guess.

**Type consistency:** `CategoryProposal.categories: list<ProposedCategory>`, `LinkAssignment.linkIds: list<int>`, `keepKnownIds(list<int>, list<int>): list<int>`, `proposeCategories(): CategoryProposal`, `assignLinks(): list<int>`, `applyCategory(Collection,string,list<int>): Collection` — used consistently across Tasks 3–6. Route names (`collections_organize`, `collections_organize_assign`, `collections_organize_apply`) match between controller, templates, and Task 6 Step 4.

**Known caveat (documented, not a gap):** the two AI-call orchestration methods aren't unit-tested (the vendor `Execution` return type is impractical to stub); their only non-trivial logic — id validation — is delegated to the fully-tested `keepKnownIds`, and end-to-end behaviour is covered by Task 8's manual run.
