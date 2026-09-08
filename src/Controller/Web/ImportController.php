<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\CurrentDashboard;
use App\Service\Import\NetscapeBookmarkParser;
use App\Service\Import\NetscapeBookmarksImporter;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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

            // Trace why an upload may silently fall back to the upload form (the
            // "no checkboxes offered" symptom): PHP drops the file entirely when the
            // body exceeds post_max_size / upload_max_filesize (or nginx client_max_body_size),
            // leaving $file null even though the request is a POST.
            $this->logger->info('[import] POST received', [
                'content_length' => $request->headers->get('Content-Length'),
                'has_file' => null !== $file,
                'upload_error' => null !== $file ? $file->getError() : 'no file (post_max_size exceeded?)',
                'is_valid' => null !== $file && $file->isValid(),
                'client_size' => null !== $file ? $file->getSize() : null,
                'client_name' => null !== $file ? $file->getClientOriginalName() : null,
                'client_mime' => null !== $file ? $file->getClientMimeType() : null,
                'post_max_size' => \ini_get('post_max_size'),
                'upload_max_filesize' => \ini_get('upload_max_filesize'),
                'files_keys' => array_keys($request->files->all()),
            ]);

            if (null !== $file && $file->isValid()) {
                $html = (string) file_get_contents($file->getPathname());
                $request->getSession()->set(self::SESSION_KEY, $html);

                $parsed = $this->parser->parse($html, 'Imported');
                $tree = $this->parser->folderTree($parsed);
                $this->logger->info('[import] parsed upload', [
                    'html_bytes' => \strlen($html),
                    'parsed_entries' => \count($parsed),
                    'tree_root_count' => $tree['root_count'],
                    'tree_nodes' => \count($tree['nodes']),
                ]);

                return $this->render('import/select.html.twig', ['tree' => $tree]);
            }

            $this->logger->warning('[import] upload rejected — re-showing the upload form (no folder checkboxes)');
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
