<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A single, dependency-free scratchpad: one plain textarea saved to a text file
 * in var/ — no entity, no DB, no rendering.
 */
final class NoteController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/notes', name: 'notes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('notes/index.html.twig', [
            'raw' => is_file($this->notePath()) ? (string) file_get_contents($this->notePath()) : '',
        ]);
    }

    #[Route('/notes', name: 'notes_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $content = str_replace("\r\n", "\n", $request->request->getString('content'));
        $dir = \dirname($this->notePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($this->notePath(), $content);
        $this->addFlash('success', 'note.saved');

        return $this->redirectToRoute('notes');
    }

    private function notePath(): string
    {
        return $this->projectDir.'/var/notes.md';
    }
}
