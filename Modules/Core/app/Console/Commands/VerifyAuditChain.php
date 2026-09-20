<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\AuditLogService;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Re-walk the audit log hash chain and report the first row where tampering broke it, if any';

    public function handle(AuditLogService $auditLog): int
    {
        $brokenAt = $auditLog->verifyChain();

        if ($brokenAt === null) {
            $this->info('Audit log chain intact.');

            return self::SUCCESS;
        }

        $this->error("Audit log chain broken at row id {$brokenAt}.");

        return self::FAILURE;
    }
}
