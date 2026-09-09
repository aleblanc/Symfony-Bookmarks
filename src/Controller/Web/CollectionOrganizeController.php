<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Collection;
use App\Repository\LinkRepository;
use App\Service\Ai\FolderOrganizer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CollectionOrganizeController extends AbstractController
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly FolderOrganizer $organizer,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $aiLogger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/collections/{id}/organize', name: 'collections_organize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function propose(Collection $collection): Response
    {
        // LLM generation over a large folder can take well over PHP's default
        // 30s max_execution_time. This is a deliberate, user-triggered action.
        set_time_limit(180);

        $start = microtime(true);
        try {
            $proposal = $this->organizer->proposeCategories($collection);
        } catch (\Throwable $e) {
            $this->aiLogger->error('organizer propose failed', ['collection' => $collection->getId(), 'exception' => $e->getMessage()]);
            $this->addFlash('error', $this->translator->trans('collection.organize_failed').' — '.$e->getMessage());

            return $this->redirectToRoute('collections_show', ['id' => $collection->getId()]);
        }
        $elapsed = microtime(true) - $start;

        $categories = array_map(
            static fn ($c): array => ['name' => $c->name, 'description' => $c->description, 'exampleTitles' => $c->exampleTitles],
            $proposal->categories,
        );

        return $this->render('collections/organize.html.twig', [
            'collection' => $collection,
            'categories' => $categories,
            'proposal_json' => json_encode($categories, \JSON_THROW_ON_ERROR),
            'selection' => null,
            'selected_links' => [],
            'elapsed' => $elapsed,
        ]);
    }

    #[Route('/collections/{id}/organize/assign', name: 'collections_organize_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assign(Collection $collection, Request $request): Response
    {
        $name = trim($request->request->getString('name'));
        $description = trim($request->request->getString('description'));
        if ('' === $name) {
            return $this->redirectToRoute('collections_show', ['id' => $collection->getId()]);
        }

        set_time_limit(180);

        // Phase-1 proposals are carried in a hidden field so we NEVER re-run the
        // expensive phase-1 analysis when the user tries another folder.
        $proposalJson = $request->request->getString('proposal', '[]');
        $categories = $this->decodeCategories($proposalJson);

        // Each "Regenerate" bumps the attempt counter, which raises the temperature
        // so the retry explores a different selection. Capped at 0.6: higher makes
        // this VL model emit degenerate output that LM Studio rejects (Channel Error).
        $attempt = max(0, $request->request->getInt('attempt'));
        $temperature = min(0.2 + 0.2 * $attempt, 0.6);

        $start = microtime(true);
        try {
            $ids = $this->organizer->assignLinks($collection, $name, $description, $temperature);
        } catch (\Throwable $e) {
            $this->aiLogger->error('organizer assign failed', ['collection' => $collection->getId(), 'exception' => $e->getMessage()]);
            $this->addFlash('error', $this->translator->trans('collection.organize_failed').' — '.$e->getMessage());

            return $this->redirectToRoute('collections_show', ['id' => $collection->getId()]);
        }
        $elapsed = microtime(true) - $start;

        $idSet = array_fill_keys($ids, true);
        $selectedLinks = [];
        foreach ($this->links->findForCollection($collection) as $link) {
            if (isset($idSet[(int) $link->getId()])) {
                $selectedLinks[] = $link;
            }
        }

        return $this->render('collections/organize.html.twig', [
            'collection' => $collection,
            'categories' => $categories,
            'proposal_json' => $proposalJson,
            'selection' => $name,
            'selection_description' => $description,
            'selected_links' => $selectedLinks,
            'elapsed' => $elapsed,
            'attempt' => $attempt,
            'temperature' => $temperature,
        ]);
    }

    #[Route('/collections/{id}/organize/apply', name: 'collections_organize_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(Collection $collection, Request $request): Response
    {
        $name = trim($request->request->getString('name'));
        /** @var list<int> $linkIds */
        $linkIds = array_map('intval', (array) $request->request->all('link_ids'));

        if ('' === $name || [] === $linkIds) {
            return $this->redirectToRoute('collections_show', ['id' => $collection->getId()]);
        }

        $child = $this->organizer->applyCategory($collection, $name, $linkIds);

        return $this->redirectToRoute('collections_show', ['id' => $child->getId()]);
    }

    /**
     * Decode the carried phase-1 proposal back into a template-friendly list.
     *
     * @return list<array{name: string, description: string, exampleTitles: list<string>}>
     */
    private function decodeCategories(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!\is_array($row) || !isset($row['name'])) {
                continue;
            }
            $examples = [];
            foreach ((array) ($row['exampleTitles'] ?? []) as $ex) {
                $examples[] = (string) $ex;
            }
            $out[] = [
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'exampleTitles' => $examples,
            ];
        }

        return $out;
    }
}
