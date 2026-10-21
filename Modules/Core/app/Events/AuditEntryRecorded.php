<?php

namespace Modules\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An audit-log row was written. Dispatched once the surrounding transaction commits, so nothing
 * listening hears about a change that was rolled back.
 */
class AuditEntryRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly int $auditLogId) {}
}
