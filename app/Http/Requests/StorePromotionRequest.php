<?php

namespace App\Http\Requests;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('owner') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'promotion_type'         => ['nullable', 'string', 'in:seasonal,flash,clearance,bundle,other'],
            'start_date'             => ['required', 'date'],
            'end_date'               => ['required', 'date', 'after_or_equal:start_date'],

            // Campaign Brief — primary cause-side inputs the engine consumes.
            // All nullable so partial Briefs still pass; PredictionEngine
            // handles partial input cleanly via Layer 1 fallback.
            'discount_pct'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_type'          => ['nullable', 'string', 'in:' . implode(',', Promotion::DISCOUNT_TYPES)],
            'channel_mix'            => ['nullable', 'array'],
            'channel_mix.*'          => ['string', 'in:' . implode(',', Promotion::CHANNEL_TAGS)],
            'ad_spend_band'          => ['nullable', 'string', 'in:' . implode(',', Promotion::AD_SPEND_BANDS)],
            'audience'               => ['nullable', 'string', 'in:' . implode(',', Promotion::AUDIENCES)],
            'lead_announcement_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            // Operator override. manual_uplift_pct optional; when set,
            // override_reason becomes required (audit trail integrity).
            'manual_uplift_pct'      => ['nullable', 'numeric', 'min:0', 'max:500'],
            'override_reason'        => [
                'nullable',
                'string',
                'max:500',
                'required_with:manual_uplift_pct',
            ],

            // Targeting (existing).
            'affects_all_skus'       => ['required', 'boolean'],
            'applies_to_categories'  => ['array'],
            'applies_to_categories.*'=> ['string', 'in:equipment,accessory,bundle'],
            'sku_ids'                => ['array'],
            'sku_ids.*'              => ['integer', 'exists:skus,id'],
        ];
    }
}
