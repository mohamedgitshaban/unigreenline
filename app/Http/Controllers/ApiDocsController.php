<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders docs/api/*.md as a browsable site (spec has no endpoint of its
 * own for this — it's a handoff aid for the frontend dev, not part of the
 * API surface). Deliberately reads the same markdown files the backend
 * team already maintains rather than duplicating their content into a
 * second data structure that could drift out of sync.
 */
class ApiDocsController extends Controller
{
    /**
     * @var array<string, string> slug => docs/api/{file}.md, in sidebar order
     */
    private const PAGES = [
        'overview' => 'README',
        'auth' => 'auth',
        'inventory' => 'inventory',
        'sales' => 'sales',
        'purchasing' => 'purchasing',
        'crm' => 'crm',
        'accounting' => 'accounting',
        'expenses' => 'expenses',
        'analytics' => 'analytics',
        'admin' => 'admin',
    ];

    public function index(): Response
    {
        return redirect()->route('docs.show', ['page' => 'overview']);
    }

    public function show(string $page): View
    {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        $path = base_path('docs/api/'.self::PAGES[$page].'.md');

        abort_unless(file_exists($path), 404);

        $html = Str::markdown(file_get_contents($path), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('api-docs.show', [
            'pages' => self::PAGES,
            'currentPage' => $page,
            'contentHtml' => $this->highlightMethodBadges($html),
        ]);
    }

    /**
     * Purely cosmetic: docs/api/*.md headers a human reads as "## `GET
     * /api/v1/warehouses`" render as a plain <code> block after Markdown
     * conversion — this wraps the leading HTTP verb in a colored badge span
     * so the method is scannable at a glance.
     *
     * Deliberately scoped to <h2>/<h3> headings only, not the whole page:
     * prose like "There's no `POST /invoices`" (documenting the absence of
     * an endpoint) also matches the bare method+path pattern, and badging
     * that inside a paragraph would make a non-existent endpoint look real.
     * Falls back to the untouched heading if the pattern doesn't match
     * inside it; never throws.
     */
    private function highlightMethodBadges(string $html): string
    {
        $methodPattern = '/<code>(GET|POST|PUT|PATCH|DELETE)(\s+[^<]+)<\/code>/';

        $badge = function (array $matches): string {
            // $matches[2] is already-escaped HTML (extracted from
            // Str::markdown()'s output), not raw input — must not be
            // re-escaped here or entities like `&amp;` would double-encode.
            $method = $matches[1];
            $rest = $matches[2];

            return '<code><span class="method-badge method-'.strtolower($method).'">'.$method.'</span>'.$rest.'</code>';
        };

        return preg_replace_callback(
            '/<h[23]>.*?<\/h[23]>/s',
            fn (array $heading): string => preg_replace_callback($methodPattern, $badge, $heading[0]) ?? $heading[0],
            $html
        ) ?? $html;
    }
}
