<?php

namespace Modules\Core\Notifications;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Says that changes to one kind of record should be announced to the people it concerns.
 *
 * A module registers one of these per record type it wants announced (see {@see ChangeNotificationRegistry}).
 * The people it concerns are: the users named in `ownerColumns` (on the record, or in the audit snapshot when
 * it was deleted or its owner changed), whoever `audience` adds, and — unless switched off — everyone those
 * people report to. Nobody is told about their own change, and only users who may open the record
 * (`permission`) are told.
 *
 * Adding a department means registering its record types here; nothing else in Core changes.
 */
final class WatchedEntity
{
    /**
     * @param  string  $module  Audit module name, e.g. `crm`.
     * @param  string  $entity  Audit entity type, e.g. `Customer`.
     * @param  string  $label  What to call it in a message, e.g. `customer`.
     * @param  string  $path  Collection path under /api/v1 that serves the record, e.g. `customers`.
     * @param  string  $permission  Permission needed to open the record; people without it are not notified.
     * @param  class-string<Model>|null  $model  Used to load the current record (not on delete).
     * @param  string  $nameAttribute  Attribute that names the record in a message.
     * @param  array<int, string>  $ownerColumns  Columns holding the ids of the users the record belongs to.
     * @param  Closure(array<string, mixed>, ?Model): array<int, string|null>|null  $audience  Further user ids.
     * @param  array<int, string>  $operations  Audit operations announced.
     * @param  bool  $notifyManagers  Also tell the managers of the people concerned.
     */
    public function __construct(
        public readonly string $module,
        public readonly string $entity,
        public readonly string $label,
        public readonly string $path,
        public readonly string $permission,
        public readonly ?string $model = null,
        public readonly string $nameAttribute = 'name',
        public readonly array $ownerColumns = [],
        public readonly ?Closure $audience = null,
        public readonly array $operations = ['INSERT', 'UPDATE', 'DELETE', 'APPROVE'],
        public readonly bool $notifyManagers = true,
    ) {}
}
