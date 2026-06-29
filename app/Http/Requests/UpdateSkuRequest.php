<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editable per-SKU fields surfaced in the Edit dialog.
 *
 * abc_class / xyz_class are deliberately NOT here — they're engine-managed
 * (computed from sales history by AbcXyzClassifier on each engine run).
 * Operators correct stock-level decisions via safety_stock_multiplier_override
 * instead, which short-circuits the ABC/XYZ-derived multiplier.
 */
class UpdateSkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Per the office-hours decision: all roles for now. Auth middleware on
        // the route is the only gate. Tighten later if the client wants a
        // per-role split.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name'             => ['sometimes', 'required', 'string', 'max:255'],
            'category'         => ['sometimes', 'required', 'string', 'in:equipment,accessory,bundle'],
            'supplier_id'      => ['sometimes', 'required', 'integer', 'exists:suppliers,id'],
            'lead_time_days'   => ['sometimes', 'required', 'integer', 'min:0', 'max:365'],
            'moq'              => ['sometimes', 'required', 'integer', 'min:1'],
            // Input is in SAR (decimal); converted to halalas in the controller.
            'unit_cost_sar'    => ['sometimes', 'required', 'numeric', 'min:0'],
            // Override is optional. Range mirrors the engine's plausible range
            // (0.5 = aggressive, 2.5 = heavy buffer). Empty/null = clear override.
            'safety_stock_multiplier_override' => ['nullable', 'numeric', 'min:0.1', 'max:5.0'],
        ];
    }
}
