<?php

namespace App\Services\InventoryEngine;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\InventoryDecision;
use App\Models\User;
use Carbon\Carbon;

class DecisionStatusService
{
    /**
     * Legal transition map: from → [allowed to...]
     *
     * Operators may correct mistakes by walking a recommendation in any
     * direction across the active lifecycle (pending ↔ acknowledged ↔ ordered ↔
     * in_transit ↔ received ↔ ignored). The frontend surfaces a confirmation
     * dialog for backward moves; the service is permissive. Every transition
     * is recorded in `status_history`, so the audit trail stays honest.
     *
     * SUPERSEDED is engine-managed only — users cannot transition into or out
     * of it through the public API.
     */
    private static function validTransitions(): array
    {
        $active = [
            InventoryDecision::STATUS_PENDING,
            InventoryDecision::STATUS_ACKNOWLEDGED,
            InventoryDecision::STATUS_ORDERED,
            InventoryDecision::STATUS_IN_TRANSIT,
            InventoryDecision::STATUS_RECEIVED,
            InventoryDecision::STATUS_IGNORED,
        ];

        $map = [];
        foreach ($active as $from) {
            $map[$from] = array_values(array_filter($active, fn ($to) => $to !== $from));
        }
        $map[InventoryDecision::STATUS_SUPERSEDED] = [];

        return $map;
    }

    /**
     * Lifecycle ordering used to flag backward transitions for the UI.
     * received and ignored are both terminal at the same level (4).
     */
    private const STATUS_ORDER = [
        'pending'      => 0,
        'acknowledged' => 1,
        'ordered'      => 2,
        'in_transit'   => 3,
        'received'     => 4,
        'ignored'      => 4,
    ];

    public static function isBackwardTransition(string $from, string $to): bool
    {
        $order = self::STATUS_ORDER;
        return ($order[$to] ?? 99) < ($order[$from] ?? -1);
    }

    /**
     * Before creating a new decision for a SKU, mark any open (PENDING or ACKNOWLEDGED)
     * records as SUPERSEDED. Terminal statuses (RECEIVED, IGNORED, SUPERSEDED) are untouched.
     * Returns the IDs of records that were superseded so the caller can link them to the new decision.
     */
    public function supersedePending(int $skuId, int $tenantId): array
    {
        $now  = Carbon::now();
        $note = "Superseded by new engine run on {$now->toIso8601String()}";

        $records = InventoryDecision::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sku_id', $skuId)
            ->whereIn('status', [
                InventoryDecision::STATUS_PENDING,
                InventoryDecision::STATUS_ACKNOWLEDGED,
            ])
            ->get(['id', 'status_history']);

        $ids = $records->pluck('id')->toArray();

        foreach ($records as $record) {
            $history   = $record->status_history ?? [];
            $history[] = [
                'status' => InventoryDecision::STATUS_SUPERSEDED,
                'at'     => $now->toIso8601String(),
                'by'     => null,
                'notes'  => $note,
            ];

            InventoryDecision::withoutGlobalScopes()
                ->where('id', $record->id)
                ->update([
                    'status'            => InventoryDecision::STATUS_SUPERSEDED,
                    'status_changed_at' => $now,
                    'status_changed_by' => null,
                    'status_notes'      => $note,
                    'status_history'    => json_encode($history),
                ]);
        }

        return $ids;
    }

    // ── User-initiated transitions ─────────────────────────────────────────────

    public function acknowledge(InventoryDecision $decision, User $user): void
    {
        $this->assertTransitionAllowed($decision, InventoryDecision::STATUS_ACKNOWLEDGED);
        $this->applyTransition($decision, InventoryDecision::STATUS_ACKNOWLEDGED, $user->id);
    }

    /**
     * Operator-supplied $qty wins over the previously-recorded ordered_qty,
     * which falls back to recommended_qty. Pass null to use the existing
     * fallback chain (used during walk-back transitions where the operator
     * shouldn't have to re-enter a value they already supplied).
     */
    public function markOrdered(InventoryDecision $decision, User $user, ?int $qty = null, ?string $notes = null): void
    {
        $this->assertTransitionAllowed($decision, InventoryDecision::STATUS_ORDERED);
        $this->applyTransition($decision, InventoryDecision::STATUS_ORDERED, $user->id, $notes, [
            'ordered_at'  => Carbon::now(),
            'ordered_qty' => $qty ?? $decision->ordered_qty ?? $decision->recommended_qty,
        ]);
    }

    public function markInTransit(InventoryDecision $decision, User $user, ?string $notes = null): void
    {
        $this->assertTransitionAllowed($decision, InventoryDecision::STATUS_IN_TRANSIT);
        $this->applyTransition($decision, InventoryDecision::STATUS_IN_TRANSIT, $user->id, $notes);
    }

    /**
     * Same fallback shape as markOrdered: $qty wins, then existing
     * received_qty, then ordered_qty.
     */
    public function markReceived(InventoryDecision $decision, User $user, ?int $qty = null, ?string $notes = null): void
    {
        $this->assertTransitionAllowed($decision, InventoryDecision::STATUS_RECEIVED);
        $this->applyTransition($decision, InventoryDecision::STATUS_RECEIVED, $user->id, $notes, [
            'received_at'  => Carbon::now(),
            'received_qty' => $qty ?? $decision->received_qty ?? $decision->ordered_qty,
        ]);
    }

    public function ignore(InventoryDecision $decision, User $user, string $reason): void
    {
        $this->assertTransitionAllowed($decision, InventoryDecision::STATUS_IGNORED);
        $this->applyTransition($decision, InventoryDecision::STATUS_IGNORED, $user->id, null, [
            'ignored_reason' => $reason,
        ]);
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function assertTransitionAllowed(InventoryDecision $decision, string $to): void
    {
        $from    = $decision->status;
        $allowed = self::validTransitions()[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot transition '{$from}' → '{$to}'."
            );
        }
    }

    private function applyTransition(
        InventoryDecision $decision,
        string $to,
        int $byUserId,
        ?string $notes = null,
        array $extra = [],
    ): void {
        $now     = Carbon::now();
        $history = $decision->status_history ?? [];

        $history[] = [
            'status' => $to,
            'at'     => $now->toIso8601String(),
            'by'     => $byUserId,
            'notes'  => $notes,
        ];

        InventoryDecision::withoutGlobalScopes()
            ->where('id', $decision->id)
            ->update(array_merge([
                'status'            => $to,
                'status_changed_at' => $now,
                'status_changed_by' => $byUserId,
                'status_notes'      => $notes,
                'status_history'    => json_encode($history),
            ], $extra));
    }
}
