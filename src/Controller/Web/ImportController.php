<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\CurrentDashboard;
use App\Service\Import\NetscapeBookmarksImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImportController extends AbstractController
{
    public function __construct(
        private readonly NetscapeBookmarksImporter $importer,
        private readonly CurrentDashboard $current,
    ) {
    }

    #[Route('/import', name: 'import_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $stats = null;
        if ($request->isMethod('POST')) {
            $file = $request->files->get('bookmarks');
            if (null !== $file && $file->isValid()) {
                $html = (string) file_get_contents($file->getPathname());
                $stats = $this->importer->import($html, $this->current->get());
            }
        }

        return $this->render('import/index.html.twig', ['stats' => $stats]);
    }
}
