<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePromotionRequest;
use App\Http\Requests\UpdatePromotionRequest;
use App\Models\Promotion;
use App\Models\Sku;
use App\Services\Promotions\PredictionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromotionController extends Controller
{
    public function index(): Response
    {
        $promotions = Promotion::with('skus:id,name,sku_code')
            ->latest('start_date')
            ->get()
            ->map(fn ($p) => [
                'id'                    => $p->id,
                'name'                  => $p->name,
                'promotion_type'        => $p->promotion_type,
                'start_date'            => $p->start_date->toDateString(),
                'end_date'              => $p->end_date->toDateString(),
                'expected_uplift_pct'   => $p->expected_uplift_pct,
                // Operator override pair — the form pre-fills the override
                // panel from these on edit.
                'manual_uplift_pct'     => $p->manual_uplift_pct,
                'override_reason'       => $p->override_reason,
                // Campaign Brief — pre-fills the Brief inputs on edit.
                'discount_pct'          => $p->discount_pct,
                'discount_type'         => $p->discount_type,
                'channel_mix'           => $p->channel_mix ?? [],
                'ad_spend_band'         => $p->ad_spend_band,
                'audience'              => $p->audience,
                'lead_announcement_days' => $p->lead_announcement_days,
                'affects_all_skus'      => $p->affects_all_skus,
                'applies_to_categories' => $p->applies_to_categories ?? [],
                'sku_ids'               => $p->skus->pluck('id'),
                'sku_names'             => $p->skus->pluck('name'),
            ]);

        $skus = Sku::orderBy('sku_code')->get(['id', 'sku_code', 'name', 'category']);

        return Inertia::render('Promotions/Index', compact('promotions', 'skus'));
    }

    public function store(StorePromotionRequest $request, PredictionEngine $engine): RedirectResponse
    {
        $data = $request->safe()->except('sku_ids');
        $data['expected_uplift_pct'] = $this->resolveExpectedUplift($data, $engine);

        $promotion = Promotion::create($data);
        $this->syncTargeting($promotion, $request);

        return back();
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion, PredictionEngine $engine): RedirectResponse
    {
        $data = $request->safe()->except('sku_ids');
        $data['expected_uplift_pct'] = $this->resolveExpectedUplift($data, $engine, $promotion);

        $promotion->update($data);
        $this->syncTargeting($promotion, $request);

        return back();
    }

    /**
     * Compute the value that ends up in `expected_uplift_pct` (the column
     * SARIMAX and main.py:_apply_promo_uplift consume unchanged).
     *
     *   manual_uplift_pct set?  → override wins, use it
     *   else                    → run the Brief through PredictionEngine
     *                              and store the engine's point estimate
     *
     * For updates without a Brief change, the existing prediction stays
     * via the saved expected_uplift_pct on the persisted row.
     */
    private function resolveExpectedUplift(array $data, PredictionEngine $engine, ?Promotion $existing = null): float
    {
        if (isset($data['manual_uplift_pct']) && $data['manual_uplift_pct'] !== null) {
            return (float) $data['manual_uplift_pct'];
        }

        $brief = [
            'promotion_type'         => $data['promotion_type']          ?? $existing?->promotion_type,
            'discount_pct'           => $data['discount_pct']            ?? $existing?->discount_pct,
            'discount_type'          => $data['discount_type']           ?? $existing?->discount_type,
            'channel_mix'            => $data['channel_mix']             ?? $existing?->channel_mix ?? [],
            'ad_spend_band'          => $data['ad_spend_band']           ?? $existing?->ad_spend_band,
            'audience'               => $data['audience']                ?? $existing?->audience,
            'lead_announcement_days' => $data['lead_announcement_days']  ?? $existing?->lead_announcement_days,
        ];

        return (float) $engine->predict($brief)['value'];
    }

    public function destroy(Request $request, Promotion $promotion): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $promotion->delete();

        return back();
    }

    /**
     * Engine-predicted uplift % for a promotion currently being composed
     * in the dialog. The Vue Brief form (Step 6 of the redesign) sends
     * the full Campaign Brief as input; the response carries the engine's
     * point estimate, confidence band, and the layer that produced it
     * (rules / nearest_neighbor / ml — set by the orchestrator based on
     * data availability).
     *
     * Backwards compatible: pre-Brief callers (the v1 dialog form before
     * Step 6 ships) send only sku_ids / categories / promotion_type. Those
     * fields are accepted for transition; promotion_type maps into the
     * Brief, the others are ignored. The orchestrator falls back to
     * Layer 1 (rules) cleanly when the Brief is partial.
     *
     * Response shape — superset of the v1 contract:
     *   { value, lower, upper, basis, sample_size, layer }
     */
    public function suggestUplift(Request $request, PredictionEngine $engine): JsonResponse
    {
        $request->validate([
            // Campaign Brief — primary input shape after Step 6.
            'promotion_type'         => ['nullable', 'string', 'in:seasonal,flash,clearance,bundle,other'],
            'discount_pct'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_type'          => ['nullable', 'string', 'in:' . implode(',', Promotion::DISCOUNT_TYPES)],
            'channel_mix'            => ['nullable', 'array'],
            'channel_mix.*'          => ['string', 'in:' . implode(',', Promotion::CHANNEL_TAGS)],
            'ad_spend_band'          => ['nullable', 'string', 'in:' . implode(',', Promotion::AD_SPEND_BANDS)],
            'audience'               => ['nullable', 'string', 'in:' . implode(',', Promotion::AUDIENCES)],
            'lead_announcement_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Legacy v1 fields — accepted but unused by the engine. Remove
            // after Step 6 frontend ships.
            'sku_ids'                => ['nullable', 'array'],
            'sku_ids.*'              => ['integer'],
            'categories'             => ['nullable', 'array'],
            'categories.*'           => ['string', 'in:equipment,accessory,bundle'],
        ]);

        $brief = [
            'promotion_type'         => $request->input('promotion_type'),
            'discount_pct'           => $request->input('discount_pct'),
            'discount_type'          => $request->input('discount_type'),
            'channel_mix'            => $request->input('channel_mix', []),
            'ad_spend_band'          => $request->input('ad_spend_band'),
            'audience'               => $request->input('audience'),
            'lead_announcement_days' => $request->input('lead_announcement_days'),
        ];

        return response()->json($engine->predict($brief));
    }

    /**
     * Sync promotion_skus pivot based on the targeting mode.
     *
     * Three modes:
     *   all       — affects_all_skus = true → detach all pivot rows
     *   category  — affects_all_skus = false, applies_to_categories not empty
     *               → resolve matching SKU IDs and sync pivot
     *   sku       — affects_all_skus = false, applies_to_categories empty
     *               → sync directly from sku_ids input
     */
    private function syncTargeting(Promotion $promotion, StorePromotionRequest|UpdatePromotionRequest $request): void
    {
        if ($promotion->affects_all_skus) {
            $promotion->skus()->detach();
            return;
        }

        $categories = $promotion->applies_to_categories ?? [];

        if (! empty($categories)) {
            // Category targeting: resolve all SKUs matching selected categories
            $skuIds = Sku::whereIn('category', $categories)->pluck('id')->toArray();
            $promotion->skus()->sync($skuIds);
            return;
        }

        // Specific SKU targeting
        $promotion->skus()->sync($request->input('sku_ids', []));
    }
}
