<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_index_redirects_to_the_overview_page(): void
    {
        $response = $this->get('/docs');

        $response->assertRedirect('/docs/overview');
    }

    public function test_overview_page_renders_the_readme(): void
    {
        $response = $this->get('/docs/overview');

        $response->assertOk();
        $response->assertSee('VetPharma ERP API', false);
        $response->assertSee('Seed accounts', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modulePages(): array
    {
        return [
            'auth' => ['auth'],
            'inventory' => ['inventory'],
            'sales' => ['sales'],
            'purchasing' => ['purchasing'],
            'crm' => ['crm'],
            'accounting' => ['accounting'],
            'analytics' => ['analytics'],
            'admin' => ['admin'],
        ];
    }

    #[DataProvider('modulePages')]
    public function test_each_module_page_renders(string $page): void
    {
        $response = $this->get("/docs/{$page}");

        $response->assertOk();
        $response->assertSee($page === 'crm' ? 'CRM' : ucfirst($page), false);
    }

    public function test_an_unknown_page_returns_404(): void
    {
        $response = $this->get('/docs/not-a-real-page');

        $response->assertNotFound();
    }

    public function test_method_verbs_are_wrapped_in_a_highlight_badge(): void
    {
        $response = $this->get('/docs/inventory');

        $response->assertOk();
        $response->assertSee('method-badge method-get', false);
        $response->assertSee('method-badge method-post', false);
    }

    /**
     * Regression: a naive whole-page regex also badged prose that
     * documents the *absence* of an endpoint (docs/api/sales.md: "There's
     * no `POST /invoices`"), making a non-existent endpoint look real.
     * Highlighting must be scoped to <h2>/<h3> headings only.
     */
    public function test_prose_mentioning_a_nonexistent_endpoint_is_not_badged(): void
    {
        $response = $this->get('/docs/sales');

        $response->assertOk();
        $response->assertSee("There's no <code>POST /invoices</code>", false);
    }

    /**
     * Path traversal guard: `page` is matched against a fixed whitelist, not
     * used to build a filesystem path directly.
     */
    public function test_path_traversal_attempt_returns_404(): void
    {
        $response = $this->get('/docs/'.urlencode('../../.env'));

        $response->assertNotFound();
    }
}
