<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/cors.php reads FRONTEND_URLS/APP_ENV directly via env() rather than
 * through the booted container, so it can be required standalone here —
 * spec §9.9 flagged the prototype's CORS as reflecting any Origin with
 * credentials allowed; this locks allowed_origins down by default outside
 * local/testing.
 */
class CorsConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['FRONTEND_URLS'], $_SERVER['FRONTEND_URLS'], $_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        parent::tearDown();
    }

    public function test_defaults_to_wildcard_in_local_environment_when_unset(): void
    {
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('FRONTEND_URLS', '');

        $this->assertSame(['*'], $this->loadConfig()['allowed_origins']);
    }

    public function test_defaults_to_wildcard_in_testing_environment_when_unset(): void
    {
        $this->setEnv('APP_ENV', 'testing');
        $this->setEnv('FRONTEND_URLS', '');

        $this->assertSame(['*'], $this->loadConfig()['allowed_origins']);
    }

    public function test_allows_no_origin_in_production_when_unset(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('FRONTEND_URLS', '');

        $this->assertSame([], $this->loadConfig()['allowed_origins']);
    }

    public function test_uses_explicit_frontend_urls_in_production_when_set(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('FRONTEND_URLS', 'https://app.vetpharma.com, https://admin.vetpharma.com');

        $this->assertSame(
            ['https://app.vetpharma.com', 'https://admin.vetpharma.com'],
            $this->loadConfig()['allowed_origins']
        );
    }

    public function test_credentials_support_stays_off_regardless_of_environment(): void
    {
        // Bearer-token (Sanctum) auth, not cookie-based — this must never
        // flip on just because allowed_origins is locked down.
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('FRONTEND_URLS', 'https://app.vetpharma.com');

        $this->assertFalse($this->loadConfig()['supports_credentials']);
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function loadConfig(): array
    {
        return require __DIR__.'/../../config/cors.php';
    }
}
