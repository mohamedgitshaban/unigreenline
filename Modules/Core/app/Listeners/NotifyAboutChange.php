<?php

namespace Modules\Core\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Core\Events\AuditEntryRecorded;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Notification;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Core\Support\DataScope;
use Throwable;

/**
 * Turns an audit-log row into in-app notifications for the people the record concerns, each with a
 * link to the record. A failure here is reported, never thrown: telling people must not undo the work.
 */
class NotifyAboutChange
{
    /** Housekeeping columns that are not worth announcing. */
    private const QUIET_FIELDS = ['id', 'tenant_id', 'created_at', 'updated_at', 'password', 'remember_token'];

    private const VERBS = [
        'INSERT' => 'added', 'UPDATE' => 'updated', 'DELETE' => 'deleted', 'APPROVE' => 'approved',
    ];

    public function __construct(private readonly ChangeNotificationRegistry $registry, private readonly DataScope $scope) {}

    public function handle(AuditEntryRecorded $event): void
    {
        try {
            $this->notify($event->auditLogId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notify(int $auditLogId): void
    {
        $entry = AuditLog::query()->find($auditLogId);

        if ($entry === null || $entry->tenant_id === null || $entry->entity_id === null) {
            return;
        }

        $watched = $this->registry->find($entry->module, $entry->entity_type);

        if ($watched === null || ! in_array($entry->operation, $watched->operations, true)) {
            return;
        }

        $deleted = $entry->operation === 'DELETE';
        $record = (! $deleted && $watched->model) ? $watched->model::query()->find($entry->entity_id) : null;
        $values = [...(array) $entry->prev_values, ...(array) $entry->new_values];
        $recipients = $this->recipients($watched, $entry, $record, $values);

        if ($recipients === []) {
            return;
        }

        $verb = self::VERBS[$entry->operation] ?? Str::lower($entry->operation);
        $name = $record?->getAttribute($watched->nameAttribute) ?? ($values[$watched->nameAttribute] ?? null);
        $actor = $entry->user_name ?? 'Someone';
        $changed = $entry->operation === 'UPDATE'
            ? collect(array_keys((array) $entry->new_values))->reject(fn ($field) => in_array($field, self::QUIET_FIELDS, true))->take(6)->all()
            : [];

        $title = Str::ucfirst($watched->label).' '.$verb;
        $body = "{$actor} {$verb} {$watched->label}".($name ? " \"{$name}\"" : '').'.'
            .($changed !== [] ? ' Changed: '.implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $changed)).'.' : '');

        foreach ($recipients as $userId => $url) {
            Notification::create([
                'tenant_id' => $entry->tenant_id,
                'user_id' => $userId,
                'actor_id' => $entry->user_id,
                'type' => Str::snake($watched->entity).'.'.Str::lower($entry->operation),
                'title' => $title,
                'body' => $body,
                'icon' => $deleted ? 'trash' : ($entry->operation === 'INSERT' ? 'plus' : 'edit'),
                'link_module' => $watched->module,
                'link_entity' => $watched->entity,
                'link_id' => $deleted ? null : $entry->entity_id,
                'link_url' => $deleted ? null : ($url ?? "/api/v1/{$watched->path}/{$entry->entity_id}"),
                'unread' => true,
            ]);
        }
    }

    /**
     * Who to tell, with the URL each should be sent to (null = the record's own).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private function recipients(WatchedEntity $watched, AuditLog $entry, ?Model $record, array $values): array
    {
        $direct = collect($watched->ownerColumns)->flatMap(fn (string $column) => [
            $record?->getAttribute($column),
            $values[$column] ?? null,
            ((array) $entry->prev_values)[$column] ?? null,
        ]);

        if ($watched->audience) {
            $direct = $direct->merge(($watched->audience)($values, $record));
        }

        $told = $this->eligible($entry, $direct, $watched->notifyManagers, $watched->permission)->mapWithKeys(fn (string $id) => [$id => null])->all();

        // Other modules' people (a warehouse manager for a purchase order) are told in their own terms.
        foreach ($this->registry->extraAudiences($entry->module, $entry->entity_type) as $extra) {
            $url = $extra['link'] ? ($extra['link'])($values, $record, $entry->entity_id) : null;

            foreach ($this->eligible($entry, collect(($extra['audience'])($values, $record)), $watched->notifyManagers, $extra['permission']) as $id) {
                $told[$id] ??= $url;
            }
        }

        return $told;
    }

    /**
     * The given users (and, if asked, everyone they report to) who are active, hold `$permission` and did not make the change themselves.
     *
     * @param  Collection<int, string|null>  $people
     * @return Collection<int, string>
     */
    private function eligible(AuditLog $entry, Collection $people, bool $withManagers, string $permission): Collection
    {
        $people = $people->filter()->unique()->values();
        $everyone = $withManagers
            ? $people->merge($people->flatMap(fn (string $id) => $this->scope->managersOf($entry->tenant_id, $id)))->unique()
            : $people;

        return User::query()
            ->where('tenant_id', $entry->tenant_id)
            ->where('status', 'active')
            ->whereIn('id', $everyone->reject(fn ($id) => $id === $entry->user_id)->all())
            ->get()
            ->filter(fn (User $user) => $user->can($permission))
            ->pluck('id');
    }
}
