<?php

namespace Modules\Manufacturing\Support;

use Modules\Manufacturing\Exceptions\InvalidOrderTransitionException;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;

/**
 * The life of a manufacturing order. Status never changes because a client
 * submits a new status: every change is an action (confirm, start, complete...)
 * that asks this class whether the move is allowed, then does the work that
 * goes with it.
 *
 *   draft ─confirm→ confirmed ─plan→ planned ─start→ in_progress ⇄ paused
 *     │                 │               │                 │
 *     └──────── complete (does the work: issue, produce, receive) ──→ completed
 *   draft/confirmed/planned/in_progress/paused ─cancel→ cancelled ─reopen→ draft
 *
 * A company can switch transitions off (settings.allowed_transitions) but never add one.
 */
final class OrderStateMachine
{
    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        'draft' => ['confirmed', 'cancelled', 'completed'],
        'confirmed' => ['planned', 'in_progress', 'cancelled', 'completed'],
        'planned' => ['in_progress', 'cancelled', 'completed'],
        'in_progress' => ['paused', 'completed', 'cancelled'],
        'paused' => ['in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => ['draft'],
    ];

    /** Statuses in which work is under way or still possible. */
    public const OPEN = ['draft', 'confirmed', 'planned', 'in_progress', 'paused'];

    /** Open orders that count as supply and as demand for materials. */
    public const COMMITTED = ['confirmed', 'planned', 'in_progress', 'paused'];

    /**
     * @param  array<string, array<int, string>>|null  $allowed  A company's own restriction, or null for the full machine.
     */
    public static function can(string $from, string $to, ?array $allowed = null): bool
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return false;
        }

        return $allowed === null || in_array($to, $allowed[$from] ?? [], true);
    }

    /**
     * @param  array<string, array<int, string>>|null  $allowed
     */
    public static function assert(string $from, string $to, ?array $allowed = null): void
    {
        if (! self::can($from, $to, $allowed)) {
            throw new InvalidOrderTransitionException($from, $to);
        }
    }

    /**
     * A restriction may only remove transitions, never invent ones.
     *
     * @param  array<string, mixed>  $allowed
     */
    public static function assertValidRestriction(array $allowed): void
    {
        foreach ($allowed as $from => $targets) {
            if (! isset(self::TRANSITIONS[$from]) || ! is_array($targets)) {
                throw new ManufacturingRuleException("Unknown order status '{$from}' in allowed transitions.");
            }

            foreach ($targets as $to) {
                if (! in_array($to, self::TRANSITIONS[$from], true)) {
                    throw new ManufacturingRuleException("An order cannot go from {$from} to {$to}, so it cannot be allowed.");
                }
            }
        }
    }
}
