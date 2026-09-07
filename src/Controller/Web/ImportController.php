<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\CurrentDashboard;
use App\Service\Import\NetscapeBookmarkParser;
use App\Service\Import\NetscapeBookmarksImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImportController extends AbstractController
{
    private const SESSION_KEY = 'import_html';

    public function __construct(
        private readonly NetscapeBookmarksImporter $importer,
        private readonly NetscapeBookmarkParser $parser,
        private readonly CurrentDashboard $current,
    ) {
    }

    /**
     * Step 1: upload the HTML export. On upload, parse it and show the folder
     * tree with checkboxes (step 2) instead of importing straight away.
     */
    #[Route('/import', name: 'import_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $file = $request->files->get('bookmarks');
            if (null !== $file && $file->isValid()) {
                $html = (string) file_get_contents($file->getPathname());
                $request->getSession()->set(self::SESSION_KEY, $html);

                return $this->render('import/select.html.twig', [
                    'tree' => $this->parser->folderTree($this->parser->parse($html, 'Imported')),
                ]);
            }
        }

        return $this->render('import/index.html.twig', ['stats' => null]);
    }

    /**
     * Step 2: import only the folders the user ticked.
     */
    #[Route('/import/confirm', name: 'import_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $session = $request->getSession();
        $html = $session->get(self::SESSION_KEY);
        if (!\is_string($html) || '' === $html) {
            return $this->redirectToRoute('import_index');
        }

        /** @var list<string> $selected */
        $selected = array_values(array_filter(
            (array) $request->request->all('folders'),
            'is_string',
        ));
        $stats = [] === $selected
            ? ['collections' => 0, 'links' => 0]
            : $this->importer->import($html, $this->current->get(), $selected);

        $session->remove(self::SESSION_KEY);

        return $this->render('import/index.html.twig', ['stats' => $stats]);
    }
}
