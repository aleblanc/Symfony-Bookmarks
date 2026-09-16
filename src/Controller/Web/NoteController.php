<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A single, dependency-free scratchpad. The note is a Markdown file in var/ —
 * no entity, no DB. Read on arrival, edited via a plain textarea, rendered with
 * a tiny in-house Markdown-to-HTML (input is HTML-escaped first, so output is safe).
 */
final class NoteController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/notes', name: 'notes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $raw = is_file($this->notePath()) ? (string) file_get_contents($this->notePath()) : '';

        return $this->render('notes/index.html.twig', [
            'raw' => $raw,
            'html' => $this->renderMarkdown($raw),
            'edit' => $request->query->getBoolean('edit') || '' === trim($raw),
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

    /**
     * Minimal, safe Markdown → HTML: headings, unordered lists, blockquotes,
     * bold/italic/inline-code, links, and paragraphs with soft line breaks.
     * The whole input is escaped first, so no user HTML can slip through.
     */
    private function renderMarkdown(string $md): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $md));
        $out = [];
        $para = []; // buffered paragraph lines
        $inList = false;

        foreach ($lines as $line) {
            $t = rtrim($line);

            // Blank line: end the current paragraph and list.
            if ('' === trim($t)) {
                if ([] !== $para) {
                    $out[] = '<p>'.implode('<br>', $para).'</p>';
                    $para = [];
                }
                if ($inList) {
                    $out[] = '</ul>';
                    $inList = false;
                }
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.*)$/', $t, $m)) {
                if ([] !== $para) {
                    $out[] = '<p>'.implode('<br>', $para).'</p>';
                    $para = [];
                }
                if ($inList) {
                    $out[] = '</ul>';
                    $inList = false;
                }
                $level = \strlen($m[1]);
                $out[] = "<h{$level}>".$this->inline($m[2])."</h{$level}>";
                continue;
            }
            if (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m)) {
                if ([] !== $para) {
                    $out[] = '<p>'.implode('<br>', $para).'</p>';
                    $para = [];
                }
                if (!$inList) {
                    $out[] = '<ul>';
                    $inList = true;
                }
                $out[] = '<li>'.$this->inline($m[1]).'</li>';
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $t, $m)) {
                if ([] !== $para) {
                    $out[] = '<p>'.implode('<br>', $para).'</p>';
                    $para = [];
                }
                if ($inList) {
                    $out[] = '</ul>';
                    $inList = false;
                }
                $out[] = '<blockquote>'.$this->inline($m[1]).'</blockquote>';
                continue;
            }
            if ($inList) {
                $out[] = '</ul>';
                $inList = false;
            }
            $para[] = $this->inline($t);
        }
        if ([] !== $para) {
            $out[] = '<p>'.implode('<br>', $para).'</p>';
        }
        if ($inList) {
            $out[] = '</ul>';
        }

        return implode("\n", $out);
    }

    /** Inline formatting on already-plain text (escaped inside). */
    private function inline(string $text): string
    {
        $s = htmlspecialchars($text, \ENT_QUOTES, 'UTF-8');
        // links [label](http(s)://url)
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            static fn (array $m): string => '<a href="'.$m[2].'" target="_blank" rel="noopener">'.$m[1].'</a>',
            $s
        ) ?? $s;
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $s) ?? $s;

        return $s;
    }
}
