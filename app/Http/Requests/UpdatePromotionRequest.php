<?php

namespace App\Http\Requests;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('owner') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['sometimes', 'required', 'string', 'max:255'],
            'promotion_type'         => ['nullable', 'string', 'in:seasonal,flash,clearance,bundle,other'],
            'start_date'             => ['sometimes', 'required', 'date'],
            'end_date'               => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],

            // Campaign Brief — see StorePromotionRequest for rationale.
            'discount_pct'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_type'          => ['nullable', 'string', 'in:' . implode(',', Promotion::DISCOUNT_TYPES)],
            'channel_mix'            => ['nullable', 'array'],
            'channel_mix.*'          => ['string', 'in:' . implode(',', Promotion::CHANNEL_TAGS)],
            'ad_spend_band'          => ['nullable', 'string', 'in:' . implode(',', Promotion::AD_SPEND_BANDS)],
            'audience'               => ['nullable', 'string', 'in:' . implode(',', Promotion::AUDIENCES)],
            'lead_announcement_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'manual_uplift_pct'      => ['nullable', 'numeric', 'min:0', 'max:500'],
            'override_reason'        => [
                'nullable',
                'string',
                'max:500',
                'required_with:manual_uplift_pct',
            ],

            'affects_all_skus'       => ['sometimes', 'required', 'boolean'],
            'applies_to_categories'  => ['array'],
            'applies_to_categories.*'=> ['string', 'in:equipment,accessory,bundle'],
            'sku_ids'                => ['array'],
            'sku_ids.*'              => ['integer', 'exists:skus,id'],
        ];
    }
}
