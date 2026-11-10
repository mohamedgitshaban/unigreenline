<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\RequestWatcher;
use Tests\TestCase;

class TelescopeRequestFilteringTest extends TestCase
{
    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::$entriesQueue = [];

        parent::tearDown();
    }

    public function test_does_not_record_request_with_status_200(): void
    {
        $this->handleRequestWithStatus(200);

        $this->assertCount(0, Telescope::$entriesQueue);
    }

    public function test_does_not_record_request_with_status_201(): void
    {
        $this->handleRequestWithStatus(201);

        $this->assertCount(0, Telescope::$entriesQueue);
    }

    public function test_records_request_with_status_500(): void
    {
        $this->handleRequestWithStatus(500);

        $this->assertCount(1, Telescope::$entriesQueue);
        $this->assertSame(500, Telescope::$entriesQueue[0]->content['response_status']);
    }

    /**
     * Run a request through the watcher configured in config/telescope.php.
     */
    private function handleRequestWithStatus(int $status): void
    {
        Telescope::startRecording(false);

        $watcher = new RequestWatcher(config('telescope.watchers.'.RequestWatcher::class));

        $watcher->recordRequest(new RequestHandled(
            Request::create('/api/example'),
            new Response('', $status),
        ));
    }
}
