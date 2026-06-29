<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppHeader from '@/Components/AppHeader.vue';
import RecommendationActions from '@/Components/RecommendationActions.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DecisionPill from '@/Components/DecisionPill.vue';
import ConfidencePill from '@/Components/ConfidencePill.vue';
import { exportCsv } from '@/utils/exportCsv';

type DecisionStatus = 'pending' | 'acknowledged' | 'ordered' | 'in_transit' | 'received' | 'ignored' | 'superseded';
type DecisionType = 'order' | 'watch' | 'hold' | 'order_budget_blocked';
type ConfidenceLevel = 'high' | 'medium' | 'low' | null;
type ActiveTab = 'pending' | 'in_flight' | 'audit';

interface DecisionRow {
 id: number;
 sku_id: number;
 sku_code: string;
 sku_name: string;
 supplier_name: string | null;
 decision: DecisionType;
 status: DecisionStatus;
 constrained_qty: number;
 recommended_qty: number;
 ordered_qty: number | null;
 received_qty: number | null;
 days_of_cover: number;
 forecast_demand: number;
 reorder_point: number;
 lead_time_days: number;
 confidence: ConfidenceLevel;
 updated_at: string | null;
 run_at: string | null;
}

interface AuditRow {
 id: number;
 sku_id: number;
 sku_code: string;
 sku_name: string;
 status_history: { status: string; at: string; by: number | null; notes: string | null }[];
 ignored_reason: string | null;
 updated_at: string | null;
}

interface Paginated<T> {
 data: T[];
 current_page: number;
 last_page: number;
 per_page: number;
 total: number;
 links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
 decisions: Paginated<DecisionRow> | null;
 log?: Paginated<AuditRow>;
 filters: Record<string, string>;
 statuses: string[];
 decisionTypes: string[];
 activeTab?: ActiveTab;
}>();

const tab = ref<ActiveTab>(props.activeTab ?? 'pending');

/**
 * Tab clicks navigate to the matching route so the controller hydrates the
 * correct prop set. Pending and In Flight share the index handler with a
 * `?tab=` query param; Audit Log has its own route /decisions/audit.
 */
function switchTab(target: ActiveTab): void {
 if (target === tab.value) return;
 if (target === 'audit') {
 router.visit('/decisions/audit', { preserveScroll: true });
 } else {
 router.visit('/decisions', {
 data: { tab: target },
 preserveScroll: true,
 });
 }
}

// ── Local filters (client-side search on top of server filter) ─────────────
// Status is no longer a filter — the active tab IS the status filter.
const localSearch = ref('');
const localDecision = ref(props.filters.decision ?? '');
const localConfidence = ref('');
const localSku = ref(props.filters.sku ?? '');

const hasActiveFilter = computed(() =>
 localSearch.value !== ''
 || localDecision.value !== ''
 || localConfidence.value !== ''
 || localSku.value !== ''
);

function paramsForTab(): Record<string, string> {
 const params: Record<string, string> = {};
 if (tab.value === 'in_flight') params.tab = 'in_flight';
 if (localDecision.value) params.decision = localDecision.value;
 if (localSku.value) params.sku = localSku.value;
 return params;
}

function clearFilters() {
 localSearch.value = '';
 localDecision.value = '';
 localConfidence.value = '';
 localSku.value = '';
 const params: Record<string, string> = {};
 if (tab.value === 'in_flight') params.tab = 'in_flight';
 router.visit('/decisions', { data: params, preserveScroll: true });
}

function clearSkuFilter() {
 localSku.value = '';
 const params: Record<string, string> = {};
 if (tab.value === 'in_flight') params.tab = 'in_flight';
 if (localDecision.value) params.decision = localDecision.value;
 router.visit('/decisions', { data: params, preserveScroll: true });
}

function applyServerFilters() {
 router.visit('/decisions', { data: paramsForTab(), preserveScroll: true });
}

const filteredDecisions = computed(() => {
 if (!props.decisions) return [];
 let rows = props.decisions.data;
 if (localSearch.value) {
 const q = localSearch.value.toLowerCase();
 rows = rows.filter(r => r.sku_code.toLowerCase().includes(q) || r.sku_name.toLowerCase().includes(q));
 }
 if (localConfidence.value) {
 rows = rows.filter(r => r.confidence === localConfidence.value);
 }
 return rows;
});

// ── Status tracking for optimistic updates ─────────────────────────────────
const localStatusMap = ref<Record<number, DecisionStatus>>(
 Object.fromEntries((props.decisions?.data ?? []).map(d => [d.id, d.status]))
);

function onStatusUpdated(payload: { id: number; status: DecisionStatus }) {
 localStatusMap.value[payload.id] = payload.status;
 // Reload the current route so the paginated decisions list and audit log
 // reflect server truth, including the new status_history entry. preserveState
 // keeps filters / search input intact.
 router.reload({ preserveScroll: true, preserveState: true });
}

// ── Badge helpers ──────────────────────────────────────────────────────────
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

const confidenceBadgeClass: Record<string, string> = {
 high: 'bg-green-100 text-green-700',
 medium: 'bg-amber-100 text-amber-700',
 low: 'bg-red-100 text-red-600',
};

function decisionLabel(d: string): string {
 const map: Record<string, string> = {
 order: 'Order Now',
 watch: 'Watch',
 hold: 'Hold',
 order_budget_blocked: 'Budget Blocked',
 };
 return map[d] ?? d;
}

function statusLabel(s: string): string {
 return s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

// ── Days cover colour ──────────────────────────────────────────────────────
function daysClass(row: DecisionRow): string {
 if (row.days_of_cover <= 0) return 'text-red-600 font-semibold';
 if (row.days_of_cover < row.lead_time_days) return 'text-amber-600 font-semibold';
 return 'text-green-700';
}

// ── Status lifecycle pills ─────────────────────────────────────────────────
const LIFECYCLE: DecisionStatus[] = ['pending', 'acknowledged', 'ordered', 'in_transit', 'received'];

const pillarClass: Record<string, string> = {
 pending: 'bg-slate-300',
 acknowledged:'bg-blue-400',
 ordered: 'bg-indigo-400',
 in_transit: 'bg-violet-400',
 received: 'bg-green-500',
};

function pillStep(step: DecisionStatus, current: DecisionStatus): string {
 if (LIFECYCLE.indexOf(step) < LIFECYCLE.indexOf(current)) return pillarClass[step] ?? 'bg-slate-200';
 if (step === current) return (pillarClass[step] ?? 'bg-slate-300') + ' ring-2 ring-offset-1 ring-current';
 return 'bg-slate-200';
}

// ── Relative time ──────────────────────────────────────────────────────────
function relativeTime(iso: string | null): string {
 if (!iso) return '—';
 const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
 if (diff < 1) return 'just now';
 if (diff < 60) return `${diff}m ago`;
 const h = Math.floor(diff / 60);
 if (h < 24) return `${h}h ago`;
 return `${Math.floor(h / 24)}d ago`;
}

// ── Audit log helpers ──────────────────────────────────────────────────────
interface AuditTransitionRow {
 sku_code: string;
 sku_name: string;
 from_status: string;
 to_status: string;
 at: string;
 notes: string | null;
}

const auditTransitions = computed<AuditTransitionRow[]>(() => {
 if (!props.log) return [];
 const rows: AuditTransitionRow[] = [];
 for (const d of props.log.data) {
 const history = d.status_history;
 if (!history || history.length === 0) continue;
 // First entry: from pending → first status
 if (history.length >= 1) {
 rows.push({
 sku_code: d.sku_code,
 sku_name: d.sku_name,
 from_status: 'pending',
 to_status: history[0].status,
 at: history[0].at,
 notes: history[0].notes,
 });
 }
 // Subsequent entries
 for (let i = 1; i < history.length; i++) {
 rows.push({
 sku_code: d.sku_code,
 sku_name: d.sku_name,
 from_status: history[i - 1].status,
 to_status: history[i].status,
 at: history[i].at,
 notes: history[i].notes,
 });
 }
 }
 return rows.sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());
});

// ── CSV exports ────────────────────────────────────────────────────────────
function exportDecisions() {
 // Operator-facing export — model diagnostics (model name, MAE/sMAPE,
 // intervals, hyperparameters) belong in the Reports page export, not
 // here. demand_per_day stays — operators read it as "how many do I
 // sell per day", which is operational, not technical.
 exportCsv('decisions.csv', filteredDecisions.value.map(r => ({
 sku_code: r.sku_code,
 sku_name: r.sku_name,
 decision: decisionLabel(r.decision),
 status: statusLabel(r.status),
 confidence: r.confidence ?? 'n/a',
 days_cover: r.days_of_cover,
 demand_per_day: r.forecast_demand.toFixed(2),
 recommended_qty: r.constrained_qty,
 last_updated: r.updated_at ?? '',
 })));
}

function exportAudit() {
 exportCsv('audit-log.csv', auditTransitions.value.map(r => ({
 sku_code: r.sku_code,
 sku_name: r.sku_name,
 from_status: r.from_status,
 to_status: r.to_status,
 timestamp: r.at,
 notes: r.notes ?? '',
 })));
}
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />
 <div class="max-w-7xl mx-auto px-6 lg:px-8 py-8 space-y-6">

 <!-- Page header -->
 <div class="flex items-start justify-between">
 <div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Recommendations</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Recommendation Queue & History</p>
 </div>
 </div>

 <!-- Tabs -->
 <div class="border-b border-slate-200 dark:border-slate-700">
 <div class="-mb-px flex gap-6 overflow-x-auto">
 <button
 @click="switchTab('pending')"
 class="pb-3 text-sm font-medium border-b-2 transition-colors duration-150 cursor-pointer whitespace-nowrap"
 :class="tab === 'pending'
 ? 'border-blue-600 text-blue-600'
 : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300'"
 >
 Pending
 </button>
 <button
 @click="switchTab('in_flight')"
 class="pb-3 text-sm font-medium border-b-2 transition-colors duration-150 cursor-pointer whitespace-nowrap"
 :class="tab === 'in_flight'
 ? 'border-blue-600 text-blue-600'
 : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300'"
 >
 In Flight
 </button>
 <button
 @click="switchTab('audit')"
 class="pb-3 text-sm font-medium border-b-2 transition-colors duration-150 cursor-pointer whitespace-nowrap"
 :class="tab === 'audit'
 ? 'border-blue-600 text-blue-600'
 : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300'"
 >
 Audit Log
 </button>
 </div>
 </div>

 <!-- ── Pending / In Flight (shared table) ─────────────────────────── -->
 <div v-if="tab === 'pending' || tab === 'in_flight'" class="space-y-4">

 <!-- Filter bar — status dropdown removed; the active tab is the status filter -->
 <div class="flex flex-wrap items-center gap-3">
 <input
 v-model="localSearch"
 type="search"
 placeholder="Search SKU…"
 class="rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-blue-500"
 />
 <select
 v-model="localDecision"
 @change="applyServerFilters"
 class="rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900"
 >
 <option value="">All Decisions</option>
 <option value="order">Order Now</option>
 <option value="watch">Watch</option>
 <option value="hold">Hold</option>
 <option value="order_budget_blocked">Budget Blocked</option>
 </select>
 <select
 v-model="localConfidence"
 class="rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900"
 >
 <option value="">All Confidence</option>
 <option value="high">High</option>
 <option value="medium">Medium</option>
 <option value="low">Low</option>
 </select>
 <span
 v-if="localSku"
 class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 ring-1 ring-blue-200 dark:ring-blue-800 text-xs font-semibold px-2.5 py-1"
 >
 SKU: <span class="font-mono">{{ localSku }}</span>
 <button
 type="button"
 @click="clearSkuFilter"
 class="ml-0.5 text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-200"
 :aria-label="`Clear ${localSku} filter`"
 >
 ×
 </button>
 </span>
 <button
 v-if="hasActiveFilter"
 @click="clearFilters"
 class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 rounded-lg px-3 py-2"
 >
 Clear filters
 </button>
 <button
 @click="exportDecisions"
 class="ml-auto border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-4 py-2 text-sm font-medium"
 >
 Export CSV
 </button>
 </div>

 <!-- Table -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <EmptyState
 v-if="filteredDecisions.length === 0"
 title="No recommendations found"
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
 </svg>
 </template>
 <template #action>
 <p class="text-xs text-slate-400 dark:text-slate-500">
 Try adjusting filters or
 <Link href="/" class="text-blue-600 hover:underline">run the engine</Link>
 to generate new recommendations.
 </p>
 </template>
 </EmptyState>
 <div v-else class="overflow-x-auto">
 <table class="w-full text-sm">
 <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-100 dark:border-slate-800">
 <tr>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">SKU</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Name</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center gap-1">Recommendation <GlossaryTip term="recommendation" /></div>
 </th>
 <th
 v-if="tab === 'in_flight'"
 class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap"
 >Status</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Confidence</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center justify-end gap-1">Days of Cover <GlossaryTip term="days_cover" /></div>
 </th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center justify-end gap-1">Demand/Day <GlossaryTip term="demand_rate" /></div>
 </th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center justify-end gap-1">Rec. Qty <GlossaryTip term="constrained_qty" /></div>
 </th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Last Updated</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Action</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <template v-for="row in filteredDecisions" :key="row.id">
 <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors duration-100">
 <td class="px-4 py-3 whitespace-nowrap">
 <Link
 :href="`/skus/${row.sku_id}`"
 class="font-mono text-xs text-blue-600 hover:text-blue-800 hover:underline"
 >
 {{ row.sku_code }}
 </Link>
 </td>
 <td class="px-4 py-3 whitespace-nowrap font-medium text-slate-900 dark:text-slate-100">{{ row.sku_name }}</td>
 <td class="px-4 py-3">
 <DecisionPill :decision="row.decision" />
 </td>
 <td v-if="tab === 'in_flight'" class="px-4 py-3">
 <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadgeClass[localStatusMap[row.id] ?? row.status]">
 {{ statusLabel(localStatusMap[row.id] ?? row.status) }}
 </span>
 </td>
 <td class="px-4 py-3">
 <ConfidencePill v-if="row.confidence" :confidence="row.confidence" />
 <span v-else class="text-slate-300 dark:text-slate-600 text-xs">—</span>
 </td>
 <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="daysClass(row)">
 {{ row.days_of_cover.toFixed(1) }}
 </td>
 <td class="px-4 py-3 text-right font-mono text-slate-600 dark:text-slate-300 tabular-nums">
 {{ row.forecast_demand.toFixed(2) }}
 </td>
 <td class="px-4 py-3 text-right text-slate-700 dark:text-slate-200 tabular-nums font-semibold">
 {{ row.constrained_qty }}
 </td>
 <td class="px-4 py-3 text-right text-xs text-slate-400 dark:text-slate-500 whitespace-nowrap">
 {{ relativeTime(row.updated_at) }}
 </td>
 <td class="px-4 py-3">
 <RecommendationActions
 :decision-id="row.id"
 :status="localStatusMap[row.id] ?? row.status"
 :recommended-qty="row.constrained_qty"
 :ordered-qty="row.ordered_qty"
 :sku-code="row.sku_code"
 @status-updated="onStatusUpdated"
 />
 </td>
 </tr>
 <!-- Status lifecycle pills -->
 <tr class="bg-slate-50 dark:bg-slate-800/50">
 <td :colspan="tab === 'in_flight' ? 10 : 9" class="px-4 pb-2 pt-0">
 <template v-if="['received','ignored','superseded'].includes(localStatusMap[row.id] ?? row.status)">
 <span
 class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
 :class="statusBadgeClass[localStatusMap[row.id] ?? row.status]"
 >
 {{ statusLabel(localStatusMap[row.id] ?? row.status) }}
 </span>
 </template>
 <template v-else>
 <div class="flex items-center gap-1">
 <template v-for="(step, i) in LIFECYCLE" :key="step">
 <div
 class="h-1.5 w-8 rounded-full transition-colors duration-200"
 :class="pillStep(step, localStatusMap[row.id] ?? row.status)"
 />
 <div v-if="i < LIFECYCLE.length - 1" class="h-px w-2 bg-slate-200" />
 </template>
 <span class="ml-2 text-xs text-slate-400 dark:text-slate-500">{{ statusLabel(localStatusMap[row.id] ?? row.status) }}</span>
 </div>
 </template>
 </td>
 </tr>
 </template>
 </tbody>
 </table>
 </div>
 </div>

 <!-- Pagination -->
 <div v-if="decisions && decisions.last_page > 1" class="flex items-center justify-center gap-1">
 <component
 v-for="link in decisions.links"
 :key="link.label"
 :is="link.url ? 'a' : 'span'"
 :href="link.url ?? undefined"
 v-html="link.label"
 class="px-3 py-1.5 rounded text-sm border"
 :class="link.active
 ? 'bg-blue-600 text-white border-blue-600'
 : link.url
 ? 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 cursor-pointer'
 : 'border-slate-200 dark:border-slate-700 text-slate-300 dark:text-slate-600 cursor-default'"
 />
 </div>

 </div>

 <!-- ── Audit Log ───────────────────────────────────────────────────── -->
 <div v-if="tab === 'audit'" class="space-y-4">

 <div class="flex items-center justify-end">
 <button
 @click="exportAudit"
 class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg px-4 py-2 text-sm font-medium"
 >
 Export CSV
 </button>
 </div>

 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <EmptyState
 v-if="auditTransitions.length === 0"
 title="No audit entries yet"
 description="As you acknowledge, order, and receive recommendations, each status transition will appear here."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 12h6M9 16h4"/>
 </svg>
 </template>
 </EmptyState>
 <div v-else class="overflow-x-auto">
 <table class="w-full text-sm">
 <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-100 dark:border-slate-800">
 <tr>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Date / Time</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">SKU</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Name</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">From</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">To</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Notes</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr
 v-for="(row, i) in auditTransitions"
 :key="i"
 class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors duration-100"
 >
 <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 font-mono whitespace-nowrap">
 {{ new Date(row.at).toLocaleString('en-GB') }}
 </td>
 <td class="px-4 py-3 font-mono text-xs text-blue-600">{{ row.sku_code }}</td>
 <td class="px-4 py-3 text-slate-700 dark:text-slate-200">{{ row.sku_name }}</td>
 <td class="px-4 py-3">
 <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadgeClass[row.from_status] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'">
 {{ statusLabel(row.from_status) }}
 </span>
 </td>
 <td class="px-4 py-3">
 <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusBadgeClass[row.to_status] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'">
 {{ statusLabel(row.to_status) }}
 </span>
 </td>
 <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ row.notes || '—' }}</td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>

 </div>

 </div>
 </div>
</template>
