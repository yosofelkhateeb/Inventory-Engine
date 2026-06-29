<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppHeader from '@/Components/AppHeader.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';

type SettingsMap = Record<string, string>;

const props = defineProps<{
 settings: SettingsMap;
 canEdit: boolean;
}>();

const categories = ['equipment', 'accessory', 'bundle'] as const;

/**
 * One-sentence guidance per field, sourced from ForecastSettingsSeeder.php
 * comments. Surfaced via GlossaryTip's inline-definition mode next to every
 * label so owners don't need to read docs/FORECASTING_ENGINE.md to set
 * thresholds correctly.
 */
const fieldHelp: Record<string, string> = {
 min_history_days:
 'Minimum days of sales history required before the model is trusted. Below this, the engine falls back to a simpler baseline. Equipment needs more history (~120d) than fast-turning accessories (~90d).',
 seasonality_min_days:
 'Minimum history before the model attempts to detect a yearly cycle. 365 by default — a full year of sales is the minimum signal needed to fit weekly or monthly seasonality.',
 intermittency_threshold:
 'Ratio of zero-sale days to total days. Above this, the SKU is treated as intermittent and routed to a Croston-style model instead of the regular forecaster.',
 bias_drift_threshold_pct:
 'Percentage error between the latest forecast and recent actuals that triggers a forecast re-evaluation. 15% catches genuine drift without firing on routine noise.',
 model_reeval_interval_days:
 'Maximum days between scheduled re-evaluations even when no drift is detected. Keeps forecasts fresh as new sales accumulate.',
 feedback_drift_threshold_pct:
 'Percentage deviation in recommendation outcomes (ignored / superseded / qty deltas) that triggers re-evaluation. The feedback loop reads this when classifying drift events.',
 ignored_rate_threshold:
 'Share of decisions that get ignored before flagging the forecast as out of step with operations. 0.30 = 30% ignore rate triggers re-eval.',
 superseded_rate_threshold:
 'Share of decisions that get superseded by a newer engine run before they are acted on. High supersession means the engine is changing its mind too often.',
 ordered_delta_threshold:
 'Average absolute difference between the recommended quantity and the quantity actually ordered. High delta = operators systematically override the recommendation.',
 received_delta_threshold:
 'Average absolute difference between the ordered quantity and the received quantity. High delta = supplier reliability issue worth flagging upstream.',
 confidence_coverage_target:
 'Target empirical coverage for forecast prediction intervals. 0.80 = the actual sales should fall inside the predicted band 80% of the time. Used to label a forecast High / Medium / Low confidence.',
 confidence_high_max_width_pct:
 'Maximum prediction-interval width (as % of the central forecast) that still counts as High confidence. Wider intervals are labelled Medium or Low.',
 confidence_medium_max_width_pct:
 'Maximum prediction-interval width that counts as Medium confidence. Anything wider gets the Low label.',
 k_lead:
 'Multiplier on lead time mean. Sets the base watch window — half the lead time means the engine starts watching when stock cover drops to half the lead time. Auto-fitted by calibration.',
 k_ltv:
 'Multiplier on lead time stddev. Acts as a 95% confidence z-score, mirroring safety-stock logic. Higher = more cautious watch trigger.',
 k_smape:
 'Weight applied to forecast error (sMAPE) when computing the watch threshold in days. Half-weight by default because safety stock already absorbs some demand variance.',
 k_trend:
 'Trend factor weight. Negative values narrow the watch buffer for declining-demand SKUs (less stock needed); positive widens for growing demand. Disabled at cold start (0.0).',
 min_days:
 'Floor on the watch threshold in days. Never zero — guarantees the engine still watches even instant-supply SKUs.',
 global_ceiling_days:
 'Sanity cap on the watch threshold. No SKU should require watching when its buffer is over 3 months — set this to your longest reasonable planning horizon.',
 calibration_drift_threshold_pct:
 'Percentage regression in calibration score versus the previous run that triggers a drift alert. 20% catches genuine structural shifts without firing on routine RNG-driven movement.',
 system_owner_email:
 'Recipient address for system-health alerts (calibration drift, etc). Leave blank to log alerts only — the SystemAlert row is still persisted, email is just an extra delivery channel.',
};

const fieldLabels: Record<string, string> = {
 min_history_days: 'Min. History Days',
 seasonality_min_days: 'Seasonality Min. Days',
 intermittency_threshold: 'Intermittency Threshold',
 bias_drift_threshold_pct: 'Bias Drift Threshold (%)',
 model_reeval_interval_days: 'Model Re-eval Interval (days)',
 feedback_drift_threshold_pct: 'Feedback Drift Threshold (%)',
 ignored_rate_threshold: 'Ignored Rate Threshold',
 superseded_rate_threshold: 'Superseded Rate Threshold',
 ordered_delta_threshold: 'Ordered Delta Threshold',
 received_delta_threshold: 'Received Delta Threshold',
 confidence_coverage_target: 'Coverage Target',
 confidence_high_max_width_pct: 'High Confidence Max Width (%)',
 confidence_medium_max_width_pct: 'Medium Confidence Max Width (%)',
 k_lead: 'k_lead (lead-time fraction)',
 k_ltv: 'k_ltv (lead-time variance)',
 k_smape: 'k_smape (forecast error weight)',
 k_trend: 'k_trend (demand trajectory)',
 min_days: 'Floor (days)',
 global_ceiling_days: 'Global Ceiling (days)',
 calibration_drift_threshold_pct: 'Drift Alert Threshold (%)',
};

const categoryFields = [
 'forecast.{cat}.min_history_days',
 'forecast.{cat}.seasonality_min_days',
 'forecast.{cat}.intermittency_threshold',
 'forecast.{cat}.bias_drift_threshold_pct',
];

const globalFields = [
 'forecast.bias_drift_threshold_pct',
 'forecast.model_reeval_interval_days',
 'forecast.feedback_drift_threshold_pct',
];

const feedbackFields = [
 'feedback.ignored_rate_threshold',
 'feedback.superseded_rate_threshold',
 'feedback.ordered_delta_threshold',
 'feedback.received_delta_threshold',
];

const confidenceFields = [
 'forecast.confidence_coverage_target',
 'forecast.confidence_high_max_width_pct',
 'forecast.confidence_medium_max_width_pct',
];

const calibrationCoefficientFields = [
 'decision.watch.k_lead',
 'decision.watch.k_ltv',
 'decision.watch.k_smape',
 'decision.watch.k_trend',
];

const calibrationBoundFields = [
 'decision.watch.min_days',
 'decision.watch.global_ceiling_days',
 'decision.watch.calibration_drift_threshold_pct',
];

function formatCalibratedAt(iso: string | undefined): string {
 if (!iso) return 'never';
 try {
 const d = new Date(iso);
 const now = Date.now();
 const diffSec = Math.floor((now - d.getTime()) / 1000);
 if (diffSec < 60) return 'just now';
 if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
 if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
 return `${Math.floor(diffSec / 86400)}d ago`;
 } catch {
 return iso;
 }
}

function fieldKey(template: string, cat: string): string {
 return template.replace('{cat}', cat);
}

const categoryTitles: Record<string, string> = {
 equipment: 'Equipment',
 accessory: 'Accessories',
 bundle: 'Bundles',
};

function categoryTitle(cat: string): string {
 return categoryTitles[cat] ?? (cat.charAt(0).toUpperCase() + cat.slice(1) + 's');
}

function fieldLabel(key: string): string {
 const suffix = key.split('.').pop() ?? key;
 return fieldLabels[suffix] ?? suffix.replace(/_/g, ' ');
}

function fieldHelpFor(key: string): string | undefined {
 const suffix = key.split('.').pop() ?? key;
 return fieldHelp[suffix];
}

const form = useForm<{ settings: SettingsMap }>({
 settings: { ...props.settings },
});

// ─── Edit guardrail ──────────────────────────────────────────────────────
// Settings are read-only by default even for owners — these thresholds
// directly affect forecast accuracy and downstream recommendations, so
// editing them is gated behind an explicit Unlock action and a confirm
// dialog with an "I understand" checkbox before any save commits.
const locked = ref(true);
const showConfirmDialog = ref(false);
const agreed = ref(false);

const inputsDisabled = computed(() => ! props.canEdit || locked.value);

const changedFields = computed<string[]>(() =>
 Object.keys(props.settings).filter(k => form.settings[k] !== props.settings[k])
);
const hasChanges = computed(() => changedFields.value.length > 0);

function unlockEditing() {
 locked.value = false;
}

function discardAndLock() {
 form.settings = { ...props.settings };
 form.clearErrors();
 locked.value = true;
}

function requestSave() {
 if (! props.canEdit || locked.value || ! hasChanges.value) return;
 agreed.value = false;
 showConfirmDialog.value = true;
}

function cancelSaveDialog() {
 showConfirmDialog.value = false;
 agreed.value = false;
}

function confirmSave() {
 if (! agreed.value) return;
 showConfirmDialog.value = false;
 form.patch('/settings', {
 preserveScroll: true,
 onSuccess: () => {
 // Re-lock after a successful save so the operator must re-unlock for
 // the next round of edits — prevents drift through accidental edits
 // left in a still-unlocked form.
 locked.value = true;
 agreed.value = false;
 },
 });
}

function onKeydown(e: KeyboardEvent) {
 if (e.key === 'Escape' && showConfirmDialog.value) {
 cancelSaveDialog();
 }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />
 <div class="max-w-3xl mx-auto px-6 lg:px-8 py-8 space-y-6">

 <!-- Header -->
 <div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Forecast Settings</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Configure thresholds per SKU category and global parameters.</p>
 </div>

 <!-- Read-only notice -->
 <div v-if="!canEdit" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
 You have read-only access. Only owners can modify settings.
 </div>

 <!-- Edit guardrail — locked / unlocked state for owners.
      Settings are gated behind an explicit unlock so an accidental
      keypress on a sensitive threshold can't quietly retrain the engine. -->
 <div
 v-if="canEdit && locked"
 class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/30 px-4 py-3 flex items-start gap-3"
 >
 <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0-1.1.9-2 2-2h0a2 2 0 012 2v2H12v-2zM6 11V7a6 6 0 1112 0v4h1a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2v-8a2 2 0 012-2h1z"/>
 </svg>
 <div class="flex-1 min-w-0">
 <p class="text-sm font-semibold text-blue-900 dark:text-blue-200">Settings are locked.</p>
 <p class="text-xs text-blue-800 dark:text-blue-300 mt-0.5">
 These thresholds drive forecast accuracy and downstream recommendations. Unlock to edit.
 </p>
 </div>
 <button
 type="button"
 @click="unlockEditing"
 class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-md transition-colors cursor-pointer"
 >
 Unlock to Edit
 </button>
 </div>

 <div
 v-if="canEdit && !locked"
 class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 px-4 py-3 flex items-start gap-3"
 >
 <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
 </svg>
 <div class="flex-1 min-w-0">
 <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">Editing unlocked.</p>
 <p class="text-xs text-amber-800 dark:text-amber-300 mt-0.5">
 Changes will affect forecast behavior. You'll be asked to confirm before saving.
 <span v-if="hasChanges" class="font-semibold">{{ changedFields.length }} change{{ changedFields.length === 1 ? '' : 's' }} pending.</span>
 </p>
 </div>
 <button
 type="button"
 @click="discardAndLock"
 class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 text-xs font-semibold rounded-md transition-colors cursor-pointer"
 >
 Discard & Lock
 </button>
 </div>

 <!-- Success flash -->
 <div
 v-if="form.recentlySuccessful"
 class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700 font-medium"
 >
 Settings saved.
 </div>

 <form @submit.prevent="requestSave" class="space-y-6">

 <!-- Per-category sections -->
 <div
 v-for="cat in categories"
 :key="cat"
 class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden"
 >
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ categoryTitle(cat) }}</h2>
 </div>
 <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-5">
 <div v-for="tpl in categoryFields" :key="fieldKey(tpl, cat)">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 {{ fieldLabel(fieldKey(tpl, cat)) }}
 <GlossaryTip :definition="fieldHelpFor(fieldKey(tpl, cat))" :aria-term="fieldLabel(fieldKey(tpl, cat))" />
 </label>
 <input
 v-model="form.settings[fieldKey(tpl, cat)]"
 type="number"
 min="0"
 step="0.01"
 :disabled="inputsDisabled"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent tabular-nums disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors[`settings.${fieldKey(tpl, cat)}`]" class="mt-1 text-xs text-red-500">
 {{ form.errors[`settings.${fieldKey(tpl, cat)}`] }}
 </p>
 </div>
 </div>
 </div>

 <!-- Global / drift settings -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Global Forecast</h2>
 </div>
 <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-3 gap-5">
 <div v-for="key in globalFields" :key="key">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 {{ fieldLabel(key) }}
 <GlossaryTip :definition="fieldHelpFor(key)" :aria-term="fieldLabel(key)" />
 </label>
 <input
 v-model="form.settings[key]"
 type="number"
 min="0"
 step="0.01"
 :disabled="inputsDisabled"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent tabular-nums disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors[`settings.${key}`]" class="mt-1 text-xs text-red-500">
 {{ form.errors[`settings.${key}`] }}
 </p>
 </div>
 </div>
 </div>

 <!-- Feedback loop thresholds -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Feedback Loop</h2>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Thresholds that trigger forecast re-evaluation from recommendation outcomes.</p>
 </div>
 <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-5">
 <div v-for="key in feedbackFields" :key="key">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 {{ fieldLabel(key) }}
 <GlossaryTip :definition="fieldHelpFor(key)" :aria-term="fieldLabel(key)" />
 </label>
 <input
 v-model="form.settings[key]"
 type="number"
 min="0"
 step="0.01"
 :disabled="inputsDisabled"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent tabular-nums disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors[`settings.${key}`]" class="mt-1 text-xs text-red-500">
 {{ form.errors[`settings.${key}`] }}
 </p>
 </div>
 </div>
 </div>

 <!-- Confidence label thresholds -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Confidence Labels</h2>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Controls how forecast confidence is classified on the Reports page.</p>
 </div>
 <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-3 gap-5">
 <div v-for="key in confidenceFields" :key="key">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 {{ fieldLabel(key) }}
 <GlossaryTip :definition="fieldHelpFor(key)" :aria-term="fieldLabel(key)" />
 </label>
 <input
 v-model="form.settings[key]"
 type="number"
 min="0"
 step="0.01"
 :disabled="inputsDisabled"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent tabular-nums disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors[`settings.${key}`]" class="mt-1 text-xs text-red-500">
 {{ form.errors[`settings.${key}`] }}
 </p>
 </div>
 </div>
 </div>

 <!-- Decision Calibration -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <div class="flex items-baseline justify-between gap-3">
 <div>
 <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Decision Calibration</h2>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
 Watch-threshold coefficients, auto-fitted from historical snapshots. Manual edits here are overwritten on the next bi-weekly calibration run.
 </p>
 </div>
 <div class="text-right text-xs text-slate-500 dark:text-slate-400 tabular-nums whitespace-nowrap">
 <div>
 <span class="text-slate-400 dark:text-slate-500">Last:</span>
 <span class="font-medium text-slate-700 dark:text-slate-200">{{ formatCalibratedAt(form.settings['decision.watch.calibrated_at']) }}</span>
 </div>
 <div v-if="form.settings['decision.watch.calibration_score']">
 <span class="text-slate-400 dark:text-slate-500">Score:</span>
 <span class="font-mono text-slate-700 dark:text-slate-200">{{ form.settings['decision.watch.calibration_score'] }}</span>
 <span class="text-slate-400 dark:text-slate-500 ml-1">({{ form.settings['decision.watch.calibration_objective'] || 'f2' }})</span>
 </div>
 </div>
 </div>
 </div>

 <!-- Coefficients (auto-fitted, but editable) -->
 <div class="px-6 py-5 grid grid-cols-2 sm:grid-cols-4 gap-5 border-b border-slate-100 dark:border-slate-800">
 <div v-for="key in calibrationCoefficientFields" :key="key">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 {{ fieldLabel(key) }}
 <GlossaryTip :definition="fieldHelpFor(key)" :aria-term="fieldLabel(key)" />
 </label>
 <input
 v-model="form.settings[key]"
 type="number"
 step="0.01"
 :disabled="inputsDisabled"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent tabular-nums disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors[`settings.${key}`]" class="mt-1 text-xs text-red-500">
 {{ form.errors[`settings.${key}`] }}
 </p>
 </div>
 </div>

 <!-- Bounds + drift threshold -->
 <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-3 gap-5">
 <div v-for="key in calibrationBoundFields" :key="key">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 {{ fieldLabel(key) }}
 <GlossaryTip :definition="fieldHelpFor(key)" :aria-term="fieldLabel(key)" />
 </label>
 <input
 v-model="form.settings[key]"
 type="number"
 min="0"
 step="0.01"
 :disabled="inputsDisabled"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent tabular-nums disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors[`settings.${key}`]" class="mt-1 text-xs text-red-500">
 {{ form.errors[`settings.${key}`] }}
 </p>
 </div>
 </div>
 </div>

 <!-- Notifications -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Notifications</h2>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
 System-owner email for engine-health alerts (e.g. calibration drift). Leave blank to log alerts only.
 </p>
 </div>
 <div class="px-6 py-5">
 <label class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 System Owner Email
 <GlossaryTip :definition="fieldHelp.system_owner_email" aria-term="System Owner Email" />
 </label>
 <input
 v-model="form.settings['notifications.system_owner_email']"
 type="email"
 placeholder="ops@example.com"
 :disabled="inputsDisabled"
 class="w-full sm:w-96 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-slate-50 dark:bg-slate-800 disabled:text-slate-400 "
 />
 <p v-if="form.errors['settings.notifications.system_owner_email']" class="mt-1 text-xs text-red-500">
 {{ form.errors['settings.notifications.system_owner_email'] }}
 </p>
 </div>
 </div>

 <!-- Save button (owner only) — disabled while locked or with no changes. -->
 <div v-if="canEdit" class="flex justify-end">
 <button
 type="submit"
 :disabled="form.processing || locked || !hasChanges"
 class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 active:bg-blue-800 transition-colors duration-150 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
 >
 <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
 </svg>
 Save Settings
 </button>
 </div>

 </form>
 </div>
 </div>

 <!-- Confirm-changes dialog. Fires on save click while unlocked & dirty.
      Operator must check the agreement box before the patch goes through. -->
 <Teleport to="body">
 <div
 v-if="showConfirmDialog"
 class="fixed inset-0 z-50 flex items-center justify-center p-4"
 role="dialog"
 aria-modal="true"
 aria-labelledby="settings-confirm-title"
 >
 <div class="absolute inset-0 bg-black/50" @click="cancelSaveDialog" />
 <div class="relative z-10 w-full max-w-md bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800">
 <h2 id="settings-confirm-title" class="text-base font-bold text-slate-800 dark:text-slate-100">Confirm Settings Changes</h2>
 </div>
 <div class="px-5 py-4 space-y-3">
 <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
 You're about to change forecast threshold values that drive how the engine generates recommendations. Bias drift detection, confidence labels, and watch-trigger calculations all depend on these settings.
 </p>
 <div class="rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 px-3 py-2">
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Pending changes</p>
 <p class="text-sm font-mono text-slate-700 dark:text-slate-200 tabular-nums">
 {{ changedFields.length }} field{{ changedFields.length === 1 ? '' : 's' }}
 </p>
 </div>
 <label class="flex items-start gap-2 cursor-pointer pt-1">
 <input
 type="checkbox"
 v-model="agreed"
 class="mt-0.5 w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-2 focus:ring-blue-500 cursor-pointer"
 />
 <span class="text-sm text-slate-700 dark:text-slate-200">
 I understand these changes will affect forecast behavior and downstream recommendations.
 </span>
 </label>
 </div>
 <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2 bg-slate-50 dark:bg-slate-800">
 <button
 type="button"
 @click="cancelSaveDialog"
 class="px-4 py-2 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 text-sm font-medium rounded-md transition-colors cursor-pointer"
 >
 Cancel
 </button>
 <button
 type="button"
 :disabled="!agreed || form.processing"
 @click="confirmSave"
 class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
 >
 <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
 </svg>
 Agree &amp; Save
 </button>
 </div>
 </div>
 </div>
 </Teleport>
</template>
