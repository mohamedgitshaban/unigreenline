<?php

namespace Modules\Manufacturing\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Manufacturing\Services\MrpRunService;

/**
 * Executes a recorded planning run on the queue. The run is claimed with an atomic status change, so
 * delivering this job more than once, or retrying it, plans the run only once.
 */
class RunMrpPlanningJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $runId) {}

    public function handle(MrpRunService $runs): void
    {
        $runs->execute($this->runId);
    }
}
