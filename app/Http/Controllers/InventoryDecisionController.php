<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\InventoryDecision;
use App\Services\InventoryEngine\DecisionStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryDecisionController extends Controller
{
    public function __construct(private readonly DecisionStatusService $statusService) {}

    public function transition(Request $request, InventoryDecision $decision): JsonResponse
    {
        abort_unless($request->user()->hasRole(['owner', 'warehouse']), 403);

        $request->validate([
            'action' => ['required', 'string', 'in:acknowledge,order,in_transit,receive,ignore'],
            'qty'    => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'notes'  => ['nullable', 'string', 'max:500'],
            'reason' => [
                // Required (non-empty) when ignoring; optional otherwise.
                'nullable',
                'string',
                'max:500',
                'required_if:action,ignore',
            ],
        ]);

        $user   = $request->user();
        $action = $request->input('action');
        $qty    = $request->input('qty');
        $notes  = $request->input('notes');
        $qty    = $qty !== null ? (int) $qty : null;

        try {
            match ($action) {
                'acknowledge' => $this->statusService->acknowledge($decision, $user),
                'order'       => $this->statusService->markOrdered($decision, $user, $qty, $notes),
                'in_transit'  => $this->statusService->markInTransit($decision, $user, $notes),
                'receive'     => $this->statusService->markReceived($decision, $user, $qty, $notes),
                'ignore'      => $this->statusService->ignore($decision, $user, $request->input('reason', '')),
            };
        } catch (InvalidStatusTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $decision->refresh();

        return response()->json([
            'id'                => $decision->id,
            'status'            => $decision->status,
            'status_changed_at' => $decision->status_changed_at,
        ]);
    }
}
