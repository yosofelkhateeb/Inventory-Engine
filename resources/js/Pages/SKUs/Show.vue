<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ExcelJS from 'exceljs';
import AppHeader from '@/Components/AppHeader.vue';
import RecommendationActions from '@/Components/RecommendationActions.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DecisionPill from '@/Components/DecisionPill.vue';
import SkuEditDialog from '@/Components/SkuEditDialog.vue';

type DecisionStatus = 'pending' | 'acknowledged' | 'ordered' | 'in_transit' | 'received' | 'ignored' | 'superseded';

interface Sku {
 id: number;
 sku_code: string;
 name: string;
 category: 'equipment' | 'accessory' | 'bundle';
 current_stock: number;
 in_transit_qty: number;
 reserved_qty: number;
 moq: number;
 unit_cost: number;
 unit_cost_sar: number;
 lead_time_days: number;
 abc_class: string | null;
 xyz_class: string | null;
 safety_stock_multiplier_override: number | null;
 supplier_id: number;
 supplier: { name: string };
}

interface SupplierOption {
 id: number;
 name: string;
}

interface LatestDecision {
 id: number;
 decision: string;
 status: DecisionStatus;
 constrained_qty: number;
 recommended_qty: number;
 ordered_qty: number | null;
 received_qty: number | null;
 days_of_cover: number;
 reorder_point: number;
 forecast_demand: number;
 safety_stock: number | null;
 run_at: string | null;
}

interface DecisionHistoryRow {
 id: number;
 decision: string;
 status: string;
 constrained_qty: number;
 recommended_qty: number;
 ordered_qty: number | null;
 received_qty: number | null;
 ignored_reason: string | null;
 days_of_cover: number;
 run_at: string | null;
 created_at: string | null;
}

interface ForecastModel {
 model_name: string;
 demand_rate: number;
 forecast_horizon_days: number;
 interval_lower: number | null;
 interval_upper: number | null;
 mae: number | null;
 rmse: number | null;
 smape: number | null;
 reeval_trigger: string;
 selection_rationale: string | null;
 warnings: string[];
 trained_at: string | null;
 next_review_at: string | null;
}

interface DemandProfile {
 volume_tier: string;
 volatility: string;
 intermittency: string;
 seasonality_detected: boolean;
 trend_direction: string;
 history_days_used: number;
}

interface FeedbackMetricRow {
 period_start: string | null;
 period_end: string | null;
 ignored_rate: number;
 ordered_delta: number;
 received_delta: number;
 superseded_rate: number;
}

const props = defineProps<{
 sku: Sku;
 suppliers: SupplierOption[];
 latestDecision: LatestDecision | null;
 decisionHistory: DecisionHistoryRow[];
 forecastModel: ForecastModel | null;
 demandProfile: DemandProfile | null;
 feedbackMetrics: FeedbackMetricRow[];
}>();

const decisionBadgeClass: Record<string, string> = {
 order: 'bg-red-100 text-red-700 border border-red-200',
 watch: 'bg-amber-100 text-amber-700 border border-amber-200',
 hold: 'bg-green-100 text-green-700 border border-green-200',
 order_budget_blocked: 'bg-orange-100 text-orange-700 border border-orange-200',
};

const statusBadgeClass: Record<string, string> = {
 pending: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
 acknowledged:'bg-blue-100 text-blue-700',
 ordered: 'bg-indigo-100 text-indigo-700',
 in_transit: 'bg-violet-100 text-violet-700',
 received: 'bg-green-100 text-green-700',
 ignored: 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
 superseded: 'bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-500',
};

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

function abcBadgeClass(abc: string | null): string {
 if (abc === 'A') return 'bg-red-100 text-red-700';
 if (abc === 'B') return 'bg-amber-100 text-amber-700';
 return 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300';
}

function decisionLabel(d: string): string {
 const map: Record<string, string> = {
 order: 'Order Now',
 watch: 'Watch',
 hold: 'Hold',
 order_budget_blocked: 'Budget Blocked',
 };
 return map[d] ?? d;
}

/**
 * Same unified Severe / High / Medium / Low scale used on the dashboard
 * and SkuDetailDialog. buffer = days_of_cover − lead_time_days.
 */
function urgency(daysCover: number, leadTime: number): { label: string; classes: string } {
 const buffer = daysCover - leadTime;
 if (buffer < 0) return { label: 'Severe', classes: 'text-red-600 dark:text-red-400 font-semibold' };
 if (buffer < 3) return { label: 'High',   classes: 'text-orange-600 dark:text-orange-400 font-semibold' };
 if (buffer < 7) return { label: 'Medium', classes: 'text-amber-600 dark:text-amber-400 font-semibold' };
 return                  { label: 'Low',    classes: 'text-green-600 dark:text-green-400 font-medium' };
}

/**
 * Buffer formatted with sign: '+5d' (slack) / '−3d' (past lead time) / '0d'.
 * U+2212 minus matches the dialog's formatBuffer for visual consistency.
 */
function formatBuffer(daysCover: number, leadTime: number): string {
 const buffer = daysCover - leadTime;
 const rounded = Math.round(buffer * 10) / 10;
 if (rounded === 0)  return '0d';
 if (rounded > 0)    return `+${rounded}d`;
 return `−${Math.abs(rounded)}d`;
}

function statusLabel(s: string): string {
 return s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatDate(str: string | null): string {
 if (!str) return '—';
 return new Date(str).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function profileBadge(val: string): string {
 const map: Record<string, string> = {
 high: 'bg-red-100 text-red-700',
 medium: 'bg-amber-100 text-amber-700',
 low: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
 stable: 'bg-green-100 text-green-700',
 moderate: 'bg-amber-100 text-amber-700',
 erratic: 'bg-red-100 text-red-700',
 continuous: 'bg-green-100 text-green-700',
 intermittent: 'bg-orange-100 text-orange-700',
 upward: 'bg-green-100 text-green-700',
 declining: 'bg-red-100 text-red-700',
 flat: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
 };
 return map[val] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300';
}

/**
 * Reload after a status mutation so the recommendation history table and
 * lifecycle pill reflect server state. preserveState keeps any local
 * component state (scroll, sort) intact.
 */
function onStatusUpdated(): void {
 router.reload({ preserveScroll: true, preserveState: true });
}

function decisionOutcome(row: DecisionHistoryRow): string {
 if (row.status === 'received') return `Received ${row.received_qty ?? '—'} units`;
 if (row.status === 'ignored') return row.ignored_reason ?? 'Ignored';
 return '—';
}

/**
 * Two-sheet XLSX export with technical/forecasting fields stripped.
 *  Sheet 1 — Summary: current SKU state (single row).
 *  Sheet 2 — Recommendation History: per-run snapshot + operator actuals
 *              (ordered/received/ignored reason).
 *
 * Shared columns (SKU Code, Name, Supplier, Run At, Decision, Status,
 * Recommended Qty, Days of Cover) use identical header text in both sheets
 * so they line up if the consumer pastes both into the same workbook.
 */
async function exportSummary(): Promise<void> {
 const sku = props.sku;
 const latest = props.latestDecision;
 const bufferDays = latest ? Math.round((latest.days_of_cover - sku.lead_time_days) * 10) / 10 : null;
 const urg = latest ? urgency(latest.days_of_cover, sku.lead_time_days).label : '';

 const wb = new ExcelJS.Workbook();
 wb.creator = 'Inventory Engine';
 wb.created = new Date();

 // ── Sheet 1: Summary ────────────────────────────────────────────────────
 const summary = wb.addWorksheet('Summary');
 summary.columns = [
 { header: 'SKU Code', key: 'sku_code', width: 14 },
 { header: 'Name', key: 'name', width: 32 },
 { header: 'Category', key: 'category', width: 14 },
 { header: 'Supplier', key: 'supplier', width: 24 },
 { header: 'ABC·XYZ', key: 'abc_xyz', width: 10 },
 { header: 'Current Stock', key: 'current_stock', width: 14 },
 { header: 'In Transit', key: 'in_transit', width: 12 },
 { header: 'Reserved', key: 'reserved', width: 10 },
 { header: 'Effective Position', key: 'effective_position', width: 18 },
 { header: 'MOQ', key: 'moq', width: 8 },
 { header: 'Unit Cost (SAR)', key: 'unit_cost_sar', width: 16 },
 { header: 'Lead Time (days)', key: 'lead_time_days', width: 16 },
 { header: 'Run At', key: 'run_at', width: 22 },
 { header: 'Decision', key: 'decision', width: 14 },
 { header: 'Status', key: 'status', width: 14 },
 { header: 'Recommended Qty', key: 'recommended_qty', width: 16 },
 { header: 'Days of Cover', key: 'days_of_cover', width: 14 },
 { header: 'Buffer (days)', key: 'buffer_days', width: 14 },
 { header: 'Urgency', key: 'urgency', width: 10 },
 ];
 summary.getRow(1).font = { bold: true };
 summary.addRow({
 sku_code: sku.sku_code,
 name: sku.name,
 category: sku.category,
 supplier: sku.supplier.name,
 abc_xyz: sku.abc_class && sku.xyz_class ? `${sku.abc_class}·${sku.xyz_class}` : '',
 current_stock: sku.current_stock,
 in_transit: sku.in_transit_qty,
 reserved: sku.reserved_qty,
 effective_position: effectivePosition,
 moq: sku.moq,
 unit_cost_sar: sku.unit_cost_sar,
 lead_time_days: sku.lead_time_days,
 run_at: latest?.run_at ?? '',
 decision: latest ? decisionLabel(latest.decision) : '',
 status: latest ? statusLabel(latest.status) : '',
 recommended_qty: latest?.constrained_qty ?? '',
 days_of_cover: latest?.days_of_cover ?? '',
 buffer_days: bufferDays,
 urgency: urg,
 });

 // ── Sheet 2: Recommendation History ────────────────────────────────────
 const history = wb.addWorksheet('Recommendation History');
 history.columns = [
 { header: 'SKU Code', key: 'sku_code', width: 14 },
 { header: 'Name', key: 'name', width: 32 },
 { header: 'Supplier', key: 'supplier', width: 24 },
 { header: 'Run At', key: 'run_at', width: 22 },
 { header: 'Decision', key: 'decision', width: 14 },
 { header: 'Status', key: 'status', width: 14 },
 { header: 'Recommended Qty', key: 'recommended_qty', width: 16 },
 { header: 'Days of Cover', key: 'days_of_cover', width: 14 },
 { header: 'Ordered Qty', key: 'ordered_qty', width: 12 },
 { header: 'Received Qty', key: 'received_qty', width: 12 },
 { header: 'Ignored Reason', key: 'ignored_reason', width: 32 },
 ];
 history.getRow(1).font = { bold: true };
 props.decisionHistory.forEach(d => {
 history.addRow({
 sku_code: sku.sku_code,
 name: sku.name,
 supplier: sku.supplier.name,
 run_at: d.run_at ?? d.created_at ?? '',
 decision: decisionLabel(d.decision),
 status: statusLabel(d.status),
 recommended_qty: d.constrained_qty,
 days_of_cover: d.days_of_cover,
 ordered_qty: d.ordered_qty ?? '',
 received_qty: d.received_qty ?? '',
 ignored_reason: d.ignored_reason ?? '',
 });
 });

 const buffer = await wb.xlsx.writeBuffer();
 const blob = new Blob([buffer], {
 type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
 });
 const url = URL.createObjectURL(blob);
 const a = document.createElement('a');
 a.href = url;
 a.download = `${sku.sku_code}-summary.xlsx`;
 a.click();
 URL.revokeObjectURL(url);
}

const effectivePosition = (props.sku.current_stock ?? 0) + (props.sku.in_transit_qty ?? 0) - (props.sku.reserved_qty ?? 0);

// Technical-detail visibility — collapsed by default. Operator-facing fields
// (decision, recommended qty, days cover, urgency, lead time, MOQ) stay
// always visible. Forecast model diagnostics, demand profile, feedback
// metrics, and the raw demand-rate field hide behind this toggle.
const showTechnical = ref(false);

// Open Edit dialog automatically when navigated here with ?edit=1
// (used by the SKU detail popup's Edit button on Dashboard).
const editOpen = ref(
 typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('edit')
);
function openEdit() { editOpen.value = true; }
function closeEdit() { editOpen.value = false; }
function onSkuSaved() { router.reload({ preserveScroll: true, preserveState: true }); }
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />
 <div class="max-w-5xl mx-auto px-6 lg:px-8 py-8 space-y-6">

 <!-- Back + Technical toggle + Export -->
 <div class="flex items-center justify-between gap-3 flex-wrap">
 <Link
 href="/skus"
 class="inline-flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 transition-colors duration-150"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
 </svg>
 Back to SKUs
 </Link>
 <div class="flex items-center gap-2">
 <button
 type="button"
 @click="openEdit"
 class="inline-flex items-center gap-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-3 py-2 text-sm font-medium transition-colors cursor-pointer"
 title="Edit SKU details (name, category, supplier, lead time, MOQ, unit cost, safety-stock override)"
 >
 <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
 </svg>
 Edit
 </button>
 <button
 type="button"
 @click="showTechnical = !showTechnical"
 class="inline-flex items-center gap-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-3 py-2 text-sm font-medium transition-colors cursor-pointer"
 :title="showTechnical ? 'Hide forecast model diagnostics, demand profile, and feedback metrics' : 'Show forecast model diagnostics, demand profile, and feedback metrics'"
 >
 <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
 </svg>
 {{ showTechnical ? 'Hide Technical Details' : 'Show Technical Details' }}
 </button>
 <button
 @click="exportSummary"
 class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-4 py-2 text-sm font-medium"
 >
 Export Summary
 </button>
 </div>
 </div>

 <!-- Header section -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
 <div class="flex items-start gap-4">
 <div class="flex-1">
 <div class="flex items-center gap-2 mb-1">
 <span class="font-mono text-xs bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded">{{ sku.sku_code }}</span>
 <span
 v-if="sku.abc_class && sku.xyz_class"
 class="text-xs font-bold font-mono px-2 py-0.5 rounded"
 :class="abcBadgeClass(sku.abc_class)"
 >
 {{ sku.abc_class }}·{{ sku.xyz_class }}
 </span>
 </div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ sku.name }}</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ sku.supplier.name }}</p>
 </div>
 </div>

 <!-- Key metrics row -->
 <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mt-6 pt-6 border-t border-slate-100 dark:border-slate-800">
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">On Hand</p>
 <GlossaryTip term="on_hand" />
 </div>
 <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ sku.current_stock }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">In Transit</p>
 <GlossaryTip term="in_transit" />
 </div>
 <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ sku.in_transit_qty }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Effective Position</p>
 <GlossaryTip term="effective_position" />
 </div>
 <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ effectivePosition }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Days of Cover</p>
 <GlossaryTip term="days_cover" />
 </div>
 <p class="text-2xl font-bold mt-1 tabular-nums"
 :class="latestDecision && latestDecision.days_of_cover < sku.lead_time_days ? 'text-red-600' : 'text-slate-800 dark:text-slate-100'">
 {{ latestDecision ? latestDecision.days_of_cover.toFixed(1) : '—' }}
 </p>
 </div>
 </div>
 </div>

 <!-- Current recommendation section -->
 <div v-if="latestDecision" class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 space-y-5">
 <div class="flex items-center justify-between gap-3 flex-wrap">
 <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Current Recommendation</h2>
 <div class="flex items-center gap-3">
 <span class="text-sm" :class="urgency(latestDecision.days_of_cover, sku.lead_time_days).classes">
 {{ urgency(latestDecision.days_of_cover, sku.lead_time_days).label }}<span class="opacity-70 font-normal"> · {{ formatBuffer(latestDecision.days_of_cover, sku.lead_time_days) }} buffer</span>
 </span>
 <DecisionPill :decision="latestDecision.decision" />
 </div>
 </div>

 <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-2">
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Days of Cover</p>
 <GlossaryTip term="days_cover" />
 </div>
 <p class="text-lg font-bold mt-1 tabular-nums" :class="urgency(latestDecision.days_of_cover, sku.lead_time_days).classes">{{ latestDecision.days_of_cover.toFixed(1) }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Lead Time</p>
 <GlossaryTip term="lead_time" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ sku.lead_time_days }}d</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Buffer</p>
 <GlossaryTip term="buffer" />
 </div>
 <p class="text-lg font-bold mt-1 tabular-nums" :class="urgency(latestDecision.days_of_cover, sku.lead_time_days).classes">{{ formatBuffer(latestDecision.days_of_cover, sku.lead_time_days) }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Reorder Point</p>
 <GlossaryTip term="reorder_point" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ latestDecision.reorder_point.toFixed(0) }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Safety Stock</p>
 <GlossaryTip term="safety_stock" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ latestDecision.safety_stock ?? '—' }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Demand / Day</p>
 <GlossaryTip term="demand_rate" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums font-mono">{{ latestDecision.forecast_demand.toFixed(2) }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Rec. Qty</p>
 <GlossaryTip term="constrained_qty" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ latestDecision.constrained_qty }}</p>
 </div>
 <div>
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Unit Cost</p>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums font-mono">{{ (sku.unit_cost_sar ?? sku.unit_cost / 100).toFixed(2) }} SAR</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">MOQ</p>
 <GlossaryTip term="moq" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ sku.moq }}</p>
 </div>
 </div>

 <div class="pt-3 border-t border-slate-100 dark:border-slate-800">
 <RecommendationActions
 :decision-id="latestDecision.id"
 :status="latestDecision.status"
 :recommended-qty="latestDecision.constrained_qty"
 :ordered-qty="latestDecision.ordered_qty"
 :sku-code="sku.sku_code"
 @status-updated="onStatusUpdated"
 />
 </div>
 </div>

 <!-- Forecast model section — technical detail, hidden by default -->
 <div v-if="showTechnical && forecastModel" class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 space-y-5">
 <div class="flex items-center justify-between">
 <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Forecast Model</h2>
 <span
 class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"
 :class="modelBadgeClass[forecastModel.model_name] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
 >
 {{ forecastModel.model_name }}
 </span>
 </div>

 <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Demand Rate</p>
 <GlossaryTip term="demand_rate" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums font-mono">
 {{ forecastModel.demand_rate.toFixed(2) }}
 <span class="text-xs font-normal text-slate-400 dark:text-slate-500">units/day</span>
 </p>
 </div>
 <div>
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Forecast Interval (95%)</p>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums font-mono">
 <template v-if="forecastModel.interval_lower !== null && forecastModel.interval_upper !== null">
 {{ forecastModel.interval_lower.toFixed(2) }} – {{ forecastModel.interval_upper.toFixed(2) }}
 </template>
 <template v-else>—</template>
 </p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">MAE</p>
 <GlossaryTip term="mae" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ forecastModel.mae !== null ? forecastModel.mae.toFixed(2) : '—' }}</p>
 </div>
 <div>
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">sMAPE</p>
 <GlossaryTip term="smape" />
 </div>
 <p class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1 tabular-nums">{{ forecastModel.smape !== null ? forecastModel.smape.toFixed(1) + '%' : '—' }}</p>
 </div>
 </div>

 <!-- Trained / review / trigger -->
 <div class="grid grid-cols-3 gap-4 pt-3 border-t border-slate-100 dark:border-slate-800">
 <div>
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Last Trained</p>
 <p class="text-sm text-slate-700 dark:text-slate-200 mt-1">{{ formatDate(forecastModel.trained_at) }}</p>
 </div>
 <div>
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Next Review</p>
 <p class="text-sm text-slate-700 dark:text-slate-200 mt-1">{{ formatDate(forecastModel.next_review_at) }}</p>
 </div>
 <div>
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Trigger</p>
 <p class="text-sm text-slate-700 dark:text-slate-200 mt-1">{{ triggerLabel[forecastModel.reeval_trigger] ?? forecastModel.reeval_trigger }}</p>
 </div>
 </div>

 <!-- Selection rationale -->
 <div v-if="forecastModel.selection_rationale" class="pt-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-1">Selection Rationale</p>
 <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ forecastModel.selection_rationale }}</p>
 </div>

 <!-- Demand profile -->
 <div v-if="demandProfile" class="pt-1">
 <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-2">Demand Profile</p>
 <div class="flex flex-wrap gap-2">
 <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold" :class="profileBadge(demandProfile.volume_tier)">
 Volume: {{ demandProfile.volume_tier }}
 </span>
 <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold" :class="profileBadge(demandProfile.volatility)">
 Volatility: {{ demandProfile.volatility }}
 </span>
 <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold" :class="profileBadge(demandProfile.intermittency)">
 Intermittency: {{ demandProfile.intermittency }}
 </span>
 <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"
 :class="demandProfile.seasonality_detected ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'">
 Seasonality: {{ demandProfile.seasonality_detected ? 'Detected' : 'None' }}
 </span>
 <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold" :class="profileBadge(demandProfile.trend_direction)">
 Trend: {{ demandProfile.trend_direction }}
 </span>
 <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
 {{ demandProfile.history_days_used }} days history
 </span>
 </div>
 </div>

 <!-- Warnings -->
 <div v-if="forecastModel.warnings && forecastModel.warnings.length > 0" class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3">
 <p class="text-xs font-semibold text-amber-700 uppercase tracking-wide mb-1">Warnings</p>
 <ul class="space-y-0.5">
 <li v-for="(w, i) in forecastModel.warnings" :key="i" class="text-xs text-amber-700">• {{ w }}</li>
 </ul>
 </div>
 </div>

 <!-- No forecast — also technical, only shown when toggle is on -->
 <div v-else-if="showTechnical" class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
 <EmptyState
 dense
 title="No forecast model trained yet"
 description="Run the inventory engine to train a forecast model for this SKU."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
 </svg>
 </template>
 </EmptyState>
 </div>

 <!-- Recommendation history -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
 <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Recommendation History</h2>
 <a
 :href="`/decisions/audit?sku=${sku.sku_code}`"
 class="text-xs text-blue-600 hover:underline"
 >
 View all in Audit Log →
 </a>
 </div>
 <div class="overflow-x-auto">
 <table class="w-full text-sm">
 <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-100 dark:border-slate-800">
 <tr>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Date</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Recommendation</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Status</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Rec. Qty</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Ordered Qty</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Outcome</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr
 v-for="d in decisionHistory"
 :key="d.id"
 class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors duration-100"
 >
 <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 font-mono">{{ d.run_at ?? d.created_at ?? '—' }}</td>
 <td class="px-4 py-3">
 <DecisionPill :decision="d.decision" />
 </td>
 <td class="px-4 py-3">
 <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadgeClass[d.status] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'">
 {{ statusLabel(d.status) }}
 </span>
 </td>
 <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ d.constrained_qty }}</td>
 <td class="px-4 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ d.ordered_qty ?? '—' }}</td>
 <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ decisionOutcome(d) }}</td>
 </tr>
 <tr v-if="decisionHistory.length === 0">
 <td colspan="6">
 <EmptyState
 dense
 title="No decisions yet"
 description="Recommendations from the inventory engine will be listed here with their outcome."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
 </svg>
 </template>
 </EmptyState>
 </td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>

 <!-- Feedback metrics — technical detail, hidden by default -->
 <div v-if="showTechnical && feedbackMetrics.length > 0" class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
 <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Feedback Metrics</h2>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Metrics update weekly. High ignored or superseded rate may indicate the model needs recalibration.</p>
 </div>
 <div class="overflow-x-auto">
 <table class="w-full text-sm">
 <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-100 dark:border-slate-800">
 <tr>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Period</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Ignored Rate %</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Ordered Delta</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Superseded Rate %</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr v-for="(m, i) in feedbackMetrics" :key="i" class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors">
 <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ m.period_start }} – {{ m.period_end }}</td>
 <td class="px-4 py-3 text-right tabular-nums"
 :class="m.ignored_rate > 0.3 ? 'text-amber-600 font-semibold' : 'text-slate-700 dark:text-slate-200'">
 {{ (m.ignored_rate * 100).toFixed(1) }}%
 </td>
 <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ m.ordered_delta?.toFixed(2) ?? '—' }}</td>
 <td class="px-4 py-3 text-right tabular-nums"
 :class="m.superseded_rate > 0.3 ? 'text-amber-600 font-semibold' : 'text-slate-700 dark:text-slate-200'">
 {{ (m.superseded_rate * 100).toFixed(1) }}%
 </td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>

 </div>
 </div>

 <SkuEditDialog
 :open="editOpen"
 :sku="{
 id: sku.id,
 sku_code: sku.sku_code,
 name: sku.name,
 category: sku.category,
 supplier_id: sku.supplier_id,
 lead_time_days: sku.lead_time_days,
 moq: sku.moq,
 unit_cost_sar: sku.unit_cost_sar,
 abc_class: sku.abc_class,
 xyz_class: sku.xyz_class,
 safety_stock_multiplier_override: sku.safety_stock_multiplier_override,
 }"
 :suppliers="suppliers"
 @close="closeEdit"
 @saved="onSkuSaved"
 />
</template>
