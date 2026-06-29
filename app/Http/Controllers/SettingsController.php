<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Setting groups exposed by this controller. The calibration coefficients
     * live in `decision_calibration` because they're auto-rewritten by the
     * RunDecisionCalibrationJob; everything else lives in `forecasting`.
     */
    private const VISIBLE_GROUPS = ['forecasting', 'decision_calibration'];

    public function index(): Response
    {
        $tenantId = Auth::user()->tenant_id ?? 1;

        $settings = SystemSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('group', self::VISIBLE_GROUPS)
            ->orderBy('key')
            ->get(['key', 'value'])
            ->keyBy('key')
            ->map(fn ($s) => $s->value);

        return Inertia::render('Settings/Index', [
            'settings'  => $settings,
            'canEdit'   => Auth::user()->hasRole('owner'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $tenantId = Auth::user()->tenant_id ?? 1;

        // Per-key validation. Most settings are non-negative thresholds or
        // percentages, but a few have specialised types:
        //   - `decision.watch.k_trend` is signed (declining demand uses
        //     a negative coefficient to narrow the watch buffer)
        //   - email keys (`notifications.system_owner_email`) are
        //     nullable strings, optional email format
        //
        // Setting keys themselves contain dots (e.g. `forecast.bias...`)
        // which Laravel's validator treats as nested array access. Escape
        // them with `\.` so the rule key matches the flat input shape.
        $signedKeys = ['decision.watch.k_trend'];
        $emailKeys  = ['notifications.system_owner_email'];

        $rules = ['settings' => ['required', 'array']];
        foreach ($request->input('settings', []) as $key => $_) {
            $escaped = str_replace('.', '\\.', $key);
            if (in_array($key, $emailKeys, true)) {
                $rules["settings.{$escaped}"] = ['present', 'nullable', 'string', 'email'];
            } elseif (in_array($key, $signedKeys, true)) {
                $rules["settings.{$escaped}"] = ['required', 'numeric'];
            } else {
                $rules["settings.{$escaped}"] = ['required', 'numeric', 'min:0'];
            }
        }
        $validated = $request->validate($rules);

        foreach ($validated['settings'] as $key => $value) {
            $existing = SystemSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->first();

            if ($existing) {
                // Preserve the existing group so calibration-managed keys
                // don't migrate to 'forecasting' when an owner edits them.
                $existing->update(['value' => (string) $value]);
            } else {
                SystemSetting::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'key'       => $key,
                    'value'     => (string) $value,
                    'group'     => 'forecasting',
                ]);
            }
        }

        return back()->with('success', 'Settings saved.');
    }
}
