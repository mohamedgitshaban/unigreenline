<?php

namespace Modules\Core\Notifications;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Listeners\NotifyAboutChange;

/**
 * Which record types announce their changes, and to whom. Every module registers its own in its
 * service provider's boot():
 *
 *     app(ChangeNotificationRegistry::class)->watch(new WatchedEntity(
 *         module: 'crm', entity: 'Customer', label: 'customer', path: 'customers',
 *         permission: 'customers.view', model: Customer::class, ownerColumns: ['sales_rep_id'],
 *     ));
 *
 * The announcement itself is made by {@see NotifyAboutChange}, which reacts to the
 * audit log, so a record type that is audited and registered here is announced with no further code.
 */
class ChangeNotificationRegistry
{
    /** @var array<string, WatchedEntity> */
    private array $entities = [];

    /** @var array<string, array<int, array{audience: Closure, permission: string, link: ?Closure}>> */
    private array $extraAudiences = [];

    public function watch(WatchedEntity $entity): void
    {
        $this->entities["{$entity->module}:{$entity->entity}"] = $entity;
    }

    /**
     * Lets another module tell more people about a record type it does not own — the warehouse manager who
     * will receive a purchase order, say. Those people are told on their own terms: they need `$permission`
     * (not the record's own), and `$link` may point them at their own view of the record.
     *
     * @param  Closure(array<string, mixed>, ?Model): array<int, string|null>  $audience  User ids.
     * @param  Closure(array<string, mixed>, ?Model, string): ?string|null  $link  Receives the snapshot, the record and the record id; returns the URL.
     */
    public function alsoNotify(string $module, string $entity, Closure $audience, string $permission, ?Closure $link = null): void
    {
        $this->extraAudiences["{$module}:{$entity}"][] = ['audience' => $audience, 'permission' => $permission, 'link' => $link];
    }

    /**
     * @return array<int, array{audience: Closure, permission: string, link: ?Closure}>
     */
    public function extraAudiences(string $module, string $entity): array
    {
        return $this->extraAudiences["{$module}:{$entity}"] ?? [];
    }

    public function find(string $module, string $entity): ?WatchedEntity
    {
        return $this->entities["{$module}:{$entity}"] ?? null;
    }

    /**
     * @return array<string, WatchedEntity>
     */
    public function all(): array
    {
        return $this->entities;
    }
}
