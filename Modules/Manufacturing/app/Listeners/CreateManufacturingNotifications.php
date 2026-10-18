<?php

namespace Modules\Manufacturing\Listeners;

use Modules\Core\Models\Notification;
use Modules\Core\Models\User;
use Modules\Manufacturing\Events\FinishedGoodsReceived;
use Modules\Manufacturing\Events\ManufacturingEvent;
use Modules\Manufacturing\Events\MaterialShortageDetected;
use Modules\Manufacturing\Events\MrpPlanningCompleted;
use Modules\Manufacturing\Events\ProductionOrderCancelled;
use Modules\Manufacturing\Events\ProductionOrderCompleted;
use Modules\Manufacturing\Events\QualityCheckFailed;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Tells the right people when something they would want to know happens: an order they raised or
 * are assigned to finishes or is cancelled; and the managers of production hear about material
 * shortages, failed quality checks and finished planning runs. In-app notifications only; these
 * events are dispatched after the transaction that caused them commits, so nobody is told about
 * something that was rolled back.
 */
class CreateManufacturingNotifications
{
    public function handle(ManufacturingEvent $event): void
    {
        $message = $this->message($event);

        if ($message === null) {
            return;
        }

        $order = $event->productionOrderId ? ProductionOrder::query()->find($event->productionOrderId) : null;
        $recipients = collect([$order?->created_by, $order?->assigned_to]);

        if ($message['managers']) {
            $recipients = $recipients->merge(
                User::query()->where('tenant_id', $event->tenantId)->get()->filter(fn (User $u) => $u->can('production-orders.confirm'))->pluck('id'),
            );
        }

        foreach ($recipients->filter()->unique() as $userId) {
            Notification::create([
                'tenant_id' => $event->tenantId,
                'user_id' => $userId,
                'type' => $message['type'],
                'title' => $message['title'],
                'body' => $message['body'],
                'icon' => $message['icon'],
                'link_module' => 'manufacturing',
                'link_id' => $event->productionOrderId ?? ($event->data['run_id'] ?? null),
                'unread' => true,
            ]);
        }
    }

    /**
     * @return array{type: string, title: string, body: string, icon: string, managers: bool}|null
     */
    private function message(ManufacturingEvent $event): ?array
    {
        $number = $event->productionOrderId ? (ProductionOrder::query()->whereKey($event->productionOrderId)->value('number') ?? $event->productionOrderId) : null;

        return match (true) {
            $event instanceof MaterialShortageDetected => [
                'type' => 'material_shortage', 'icon' => 'alert', 'managers' => true,
                'title' => "Materials short for {$number}",
                'body' => count($event->data['shortages'] ?? []).' material(s) could not be fully reserved.',
            ],
            $event instanceof QualityCheckFailed => [
                'type' => 'quality_failed', 'icon' => 'alert', 'managers' => true,
                'title' => 'Quality check failed',
                'body' => ($event->data['name'] ?? 'A check').($number ? " on {$number}" : '').' failed and needs attention.',
            ],
            $event instanceof ProductionOrderCompleted => [
                'type' => 'production_completed', 'icon' => 'check', 'managers' => false,
                'title' => "Production order {$number} completed",
                'body' => 'The order has been closed.',
            ],
            $event instanceof ProductionOrderCancelled => [
                'type' => 'production_cancelled', 'icon' => 'x', 'managers' => false,
                'title' => "Production order {$number} cancelled",
                'body' => $event->data['reason'] ?? 'The order was cancelled.',
            ],
            $event instanceof FinishedGoodsReceived => [
                'type' => 'goods_received', 'icon' => 'package', 'managers' => false,
                'title' => "Finished goods received from {$number}",
                'body' => ($event->data['good'] ?? 0).' carton(s) received into stock.',
            ],
            $event instanceof MrpPlanningCompleted => [
                'type' => 'mrp_completed', 'icon' => 'calendar', 'managers' => true,
                'title' => 'Planning run '.($event->data['number'] ?? '').' finished',
                'body' => ($event->data['stats']['manufacture'] ?? 0).' manufacture and '.($event->data['stats']['purchase'] ?? 0).' purchase suggestion(s) proposed.',
            ],
            default => null,
        };
    }
}
