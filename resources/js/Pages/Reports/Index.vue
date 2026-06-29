<script setup lang="ts">
import { ref } from 'vue';
import AppHeader from '@/Components/AppHeader.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { exportCsv } from '@/utils/exportCsv';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface ReportRow {
 sku_id: number;
 sku_code: string;
 sku_name: string;
 model_name: string;
 demand_rate: number;
 mae: number | null;
 smape: number | null;
 interval_lower: number | null;
 interval_upper: number | null;
 interval_confidence: number | null;
 interval_empirical_coverage: number | null;
 selection_rationale: string | null;
 reeval_trigger: string | null;
 warnings: string[];
 trained_at: string | null;
 next_review_at: string | null;
}

interface StaleSku {
 sku_id: number;
 sku_code: string;
 sku_name: string;
 staleness_days: number;
 last_sale_date: string;
}

const props = defineProps<{
 rows: ReportRow[];
 wmape: number | null;
 stale_skus?: StaleSku[];
}>();

const modelBadgeClass: Record<string, string> = {
 holt_winters: 'bg-blue-100 text-blue-700',
 sarimax: 'bg-purple-100 text-purple-700',
 lightgbm: 'bg-green-100 text-green-700',
 croston: 'bg-amber-100 text-amber-700',
 prophet: 'bg-rose-100 text-rose-700',
 ets_fallback: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
};

const triggerLabel: Record<string, string> = {
 new_sku: 'New SKU',
 scheduled: 'Scheduled review',
 bias_drift: 'Bias drift detected',
 feedback_drift: 'Feedback drift detected',
 manual: 'Manual run',
};

const triggerBadgeClass: Record<string, string> = {
 scheduled: 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
 new_sku: 'bg-green-100 text-green-700',
 bias_drift: 'bg-amber-100 text-amber-700',
 feedback_drift: 'bg-orange-100 text-orange-700',
 manual: 'bg-blue-100 text-blue-600',
};

function fmt(val: number | null, decimals = 4): string {
 if (val === null) return '—';
 return val.toFixed(decimals);
}

function intervalLabel(row: ReportRow): string {
 if (row.interval_lower === null || row.interval_upper === null) return '—';
 return `${row.interval_lower.toFixed(2)} – ${row.interval_upper.toFixed(2)}`;
}

function coverageLabel(row: ReportRow): string {
 if (row.interval_empirical_coverage === null) return '—';
 const pct = (row.interval_empirical_coverage * 100).toFixed(1);
 const target = row.interval_confidence !== null ? (row.interval_confidence * 100).toFixed(0) : '95';
 const ok = row.interval_empirical_coverage >= (row.interval_confidence ?? 0.95) - 0.03;
 return `${pct}% ${ok ? '✓' : '↓'} (target ${target}%)`;
}

// Technical-detail visibility — same pattern as SKU Detail. Default OFF
// so the page lands on the operator-friendly view (SKU + demand rate);
// click "Show Technical Details" to reveal model name, error metrics,
// intervals, coverage, trigger, rationale, and trained-at columns.
const showTechnical = ref(false);

function exportReport() {
 // CSV mirrors the visible view — when technical is collapsed, the
 // export only carries the operator-facing columns.
 const rows = props.rows.map(r => {
 const base: Record<string, string> = {
 sku_code: r.sku_code,
 sku_name: r.sku_name,
 demand_rate: r.demand_rate.toFixed(4),
 };
 if (! showTechnical.value) return base;
 return {
 ...base,
 model: r.model_name,
 mae: r.mae?.toFixed(4) ?? '',
 smape_pct: r.smape?.toFixed(1) ?? '',
 interval_lower: r.interval_lower?.toFixed(2) ?? '',
 interval_upper: r.interval_upper?.toFixed(2) ?? '',
 coverage: r.interval_empirical_coverage !== null ? (r.interval_empirical_coverage * 100).toFixed(1) + '%' : '',
 trigger: triggerLabel[r.reeval_trigger ?? ''] ?? (r.reeval_trigger ?? ''),
 trained_at: r.trained_at?.substring(0, 10) ?? '',
 selection_rationale: r.selection_rationale ?? '',
 };
 });
 exportCsv('forecast-report.csv', rows);
}
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />
 <main class="max-w-7xl mx-auto px-6 lg:px-8 py-8 space-y-6">

 <!-- Page header -->
 <div class="flex items-start justify-between gap-3 flex-wrap">
 <div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ t('reports.title') }}</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ t('reports.subtitle') }}</p>
 </div>
 <div class="flex items-center gap-2">
 <button
 type="button"
 @click="showTechnical = !showTechnical"
 class="inline-flex items-center gap-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-3 py-2 text-sm font-medium transition-colors cursor-pointer"
 :title="showTechnical ? 'Hide model name, MAE, sMAPE, intervals, coverage, trigger, rationale, and trained-at columns' : 'Show forecast model diagnostics'"
 >
 <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
 </svg>
 {{ showTechnical ? 'Hide Technical Details' : 'Show Technical Details' }}
 </button>
 <button
 @click="exportReport"
 class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-4 py-2 text-sm font-medium"
 >
 Export CSV
 </button>
 </div>
 </div>

 <!-- Stale-feed banner -->
 <div
 v-if="props.stale_skus && props.stale_skus.length > 0"
 class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3"
 role="alert"
 >
 <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
 </svg>
 <div class="flex-1 min-w-0">
 <p class="text-sm font-semibold text-amber-900">
 {{ props.stale_skus.length }} SKU{{ props.stale_skus.length === 1 ? '' : 's' }} not reporting recent sales
 </p>
 <p class="text-xs text-amber-700 mt-0.5">
 Forecasts trained on stale feeds will drift. Check that ingestion is current.
 </p>
 <ul class="mt-2 text-xs text-amber-800 space-y-0.5">
 <li
 v-for="s in props.stale_skus.slice(0, 5)"
 :key="s.sku_id"
 class="flex items-center gap-2"
 >
 <span class="font-mono text-amber-900">{{ s.sku_code }}</span>
 <span class="truncate">{{ s.sku_name }}</span>
 <span class="text-amber-600 tabular-nums whitespace-nowrap">
 · last sale {{ s.last_sale_date }} ({{ s.staleness_days }}d)
 </span>
 </li>
 <li v-if="props.stale_skus.length > 5" class="text-amber-700 italic">
 … and {{ props.stale_skus.length - 5 }} more
 </li>
 </ul>
 </div>
 </div>

 <!-- WMAPE card -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
 <div class="flex items-center gap-2 mb-2">
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Portfolio WMAPE</p>
 <GlossaryTip term="wmape" />
 </div>
 <p class="text-4xl font-bold tabular-nums"
 :class="wmape !== null
 ? (wmape <= 20 ? 'text-green-700' : wmape <= 40 ? 'text-amber-600' : 'text-red-600')
 : 'text-slate-300 dark:text-slate-600'"
 >
 {{ wmape !== null ? wmape.toFixed(1) + '%' : t('wmape.no_data') }}
 </p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">{{ t('wmape.tooltip') }}</p>
 </div>

 <!-- Table -->
 <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
 <EmptyState
 v-if="rows.length === 0"
 :title="t('reports.no_data')"
 description="Run the inventory engine to train forecast models and populate this report."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
 </svg>
 </template>
 </EmptyState>

 <div v-else class="overflow-x-auto">
 <table class="min-w-full text-sm">
 <thead>
 <tr class="border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.sku') }}</th>
 <th v-if="showTechnical" class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.model') }}</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
 <div class="flex items-center justify-end gap-1">
 {{ t('reports.demand_rate') }} <GlossaryTip term="demand_rate" />
 </div>
 </th>
 <th v-if="showTechnical" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
 <div class="flex items-center justify-end gap-1">
 {{ t('reports.mae') }} <GlossaryTip term="mae" />
 </div>
 </th>
 <th v-if="showTechnical" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
 <div class="flex items-center justify-end gap-1">
 {{ t('reports.smape') }} <GlossaryTip term="smape" />
 </div>
 </th>
 <th v-if="showTechnical" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.interval') }}</th>
 <th v-if="showTechnical" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.coverage') }}</th>
 <th v-if="showTechnical" class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.trigger') }}</th>
 <th v-if="showTechnical" class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.rationale') }}</th>
 <th v-if="showTechnical" class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('reports.trained_at') }}</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr
 v-for="row in rows"
 :key="row.sku_id"
 class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors"
 >
 <td class="px-4 py-3">
 <span class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ row.sku_code }}</span>
 <span class="block text-slate-700 dark:text-slate-200 font-medium leading-tight">{{ row.sku_name }}</span>
 </td>
 <td v-if="showTechnical" class="px-4 py-3">
 <span
 class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
 :class="modelBadgeClass[row.model_name] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
 >
 {{ row.model_name }}
 </span>
 </td>
 <td class="px-4 py-3 text-right font-mono text-slate-700 dark:text-slate-200 tabular-nums">
 {{ fmt(row.demand_rate) }}
 <span class="text-xs text-slate-400 dark:text-slate-500 ml-0.5">{{ t('reports.units_per_day') }}</span>
 </td>
 <td v-if="showTechnical" class="px-4 py-3 text-right font-mono text-slate-700 dark:text-slate-200 tabular-nums">{{ fmt(row.mae, 4) }}</td>
 <td v-if="showTechnical" class="px-4 py-3 text-right font-mono tabular-nums"
 :class="row.smape !== null && row.smape > 50 ? 'text-amber-600 font-semibold' : 'text-slate-700 dark:text-slate-200'"
 >
 {{ row.smape !== null ? row.smape.toFixed(1) + '%' : '—' }}
 </td>
 <td v-if="showTechnical" class="px-4 py-3 text-right font-mono text-slate-600 dark:text-slate-300 tabular-nums text-xs">{{ intervalLabel(row) }}</td>
 <td v-if="showTechnical" class="px-4 py-3 text-right text-xs"
 :class="row.interval_empirical_coverage !== null && row.interval_empirical_coverage < (row.interval_confidence ?? 0.95) - 0.03 ? 'text-amber-600 font-semibold' : 'text-slate-600 dark:text-slate-300'"
 >
 {{ coverageLabel(row) }}
 </td>
 <td v-if="showTechnical" class="px-4 py-3">
 <span
 v-if="row.reeval_trigger"
 class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
 :class="triggerBadgeClass[row.reeval_trigger] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'"
 >
 {{ triggerLabel[row.reeval_trigger] ?? row.reeval_trigger }}
 </span>
 <span v-else class="text-slate-300 dark:text-slate-600">—</span>
 </td>
 <td v-if="showTechnical" class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 max-w-xs truncate" :title="row.selection_rationale ?? ''">
 {{ row.selection_rationale || '—' }}
 </td>
 <td v-if="showTechnical" class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 tabular-nums whitespace-nowrap">
 {{ row.trained_at ? row.trained_at.substring(0, 10) : '—' }}
 </td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>

 </main>
 </div>
</template>
