<?php

namespace Modules\Accounting\Tests\Feature\Services;

use Modules\Accounting\Database\Seeders\AccountSeeder;
use Tests\TestCase;

/**
 * Guards the chart of accounts against drifting away from the code that posts
 * to it: a flow that posts to an account nobody seeded would fail at runtime in
 * production, and a seeded account no one documented would sit unconnected.
 */
class AccountConnectionsTest extends TestCase
{
    public function test_every_seeded_account_is_described_in_the_connection_map_and_vice_versa(): void
    {
        $seeded = array_column(AccountSeeder::ACCOUNTS, 'code');
        // PHP turns numeric-looking array keys into integers.
        $mapped = array_map('strval', array_keys(config('accounting.connections')));

        sort($seeded);
        sort($mapped);

        $this->assertSame($seeded, $mapped);
    }

    public function test_every_account_code_the_application_posts_to_exists_in_the_seeded_chart(): void
    {
        $seeded = array_column(AccountSeeder::ACCOUNTS, 'code');
        $used = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('Modules'))) as $file) {
            if (! str_ends_with($file->getPathname(), '.php') || ! str_contains($file->getPathname(), '/app/')) {
                continue;
            }

            foreach (preg_split('/\R/', file_get_contents($file->getPathname())) as $line) {
                if (preg_match("/account_code'\s*=>\s*'(\d{4})'|PAYMENT_ACCOUNT_CODES\s*=\s*\[(.*)\]/", $line, $m)) {
                    preg_match_all("/'(\d{4})'/", $m[0], $codes);
                    array_push($used, ...$codes[1]);
                }
            }
        }

        $this->assertNotEmpty($used);
        $this->assertSame([], array_values(array_diff(array_unique($used), $seeded)), 'Posting to an account that is not in the seeded chart.');
    }

    public function test_every_automatically_posted_account_is_marked_as_such_and_headings_have_no_flows(): void
    {
        foreach (config('accounting.connections') as $code => $connection) {
            $this->assertContains($connection['kind'], ['header', 'posted', 'manual'], $code);
            $this->assertSame($connection['kind'] === 'header', $connection['used_by'] === [], "Account {$code}: headings have no flows and every other account names at least one.");
        }
    }
}
