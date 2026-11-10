<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Parallel requests from one API client hit the same rate-limiter row, and on
 * the database cache store MySQL aborts one with a 1213 deadlock (HTTP 500), so
 * the limiter must default to a store that does not touch the database.
 */
class CacheConfigTest extends TestCase
{
    private ?string $originalLimiterStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalLimiterStore = $_ENV['CACHE_LIMITER_STORE'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->setLimiterStore($this->originalLimiterStore);

        parent::tearDown();
    }

    public function test_rate_limiter_uses_file_store_when_unset(): void
    {
        $this->setLimiterStore(null);

        $this->assertSame('file', $this->loadConfig()['limiter']);
    }

    public function test_rate_limiter_store_can_be_overridden_by_environment(): void
    {
        $this->setLimiterStore('redis');

        $this->assertSame('redis', $this->loadConfig()['limiter']);
    }

    private function setLimiterStore(?string $store): void
    {
        if ($store === null) {
            unset($_ENV['CACHE_LIMITER_STORE'], $_SERVER['CACHE_LIMITER_STORE']);
            putenv('CACHE_LIMITER_STORE');

            return;
        }

        $_ENV['CACHE_LIMITER_STORE'] = $store;
        $_SERVER['CACHE_LIMITER_STORE'] = $store;
        putenv('CACHE_LIMITER_STORE='.$store);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        return require config_path('cache.php');
    }
}
