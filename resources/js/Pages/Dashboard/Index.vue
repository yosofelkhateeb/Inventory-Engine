<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import AppHeader from '@/Components/AppHeader.vue';
import TableControls from '@/Components/TableControls.vue';
import SkuDetailDialog from '@/Components/SkuDetailDialog.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DecisionPill from '@/Components/DecisionPill.vue';
import { useTableControls } from '@/composables/useTableControls';

type DecisionStatus = 'pending' | 'acknowledged' | 'ordered' | 'in_transit' | 'received' | 'ignored' | 'superseded';

interface DecisionRow {
 id: number;
 sku_id: number;
 sku_code: string;
 sku_name: string;
 decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked';
 status: DecisionStatus;
 constrained_qty: number;
 days_of_cover: number;
 reorder_point: number;
 forecast_demand: number;
 safety_stock: number;
 current_stock: number;
 in_transit_qty: number;
 reserved_qty: number;
 effective_position: number;
 lead_time_days: number;
 run_at: string;
 abc_class: 'A' | 'B' | 'C' | null;
 xyz_class: 'X' | 'Y' | 'Z' | null;
}

interface Stats {
 order_now: number;
 order_soon: number;
 watchlist: number;
 avg_days_cover: number;
 wmape: number | null;
}

interface LastRun {
 run_at: string;
 decisions_count: number;
 status: 'completed' | 'failed';
}

interface DeadStockSku {
 id: number;
 name: string;
 sku_code: string;
 current_stock: number;
}

interface ActivityItem {
 id: number;
 sku_name: string;
 sku_code: string;
 status: DecisionStatus;
 updated_at: string;
}

const props = defineProps<{
 stats: Stats;
 decisions: DecisionRow[];
 lastRun: LastRun | null;
 deadStock: DeadStockSku[];
 recentActivity: ActivityItem[];
}>();

const selectedSkuId = ref<number | null>(null);

const decisionBadgeClass: Record<string, string> = {
 order: 'bg-red-100 text-red-700 border border-red-200',
 watch: 'bg-amber-100 text-amber-700 border border-amber-200',
 hold: 'bg-green-100 text-green-700 border border-green-200',
 order_budget_blocked: 'bg-orange-100 text-orange-700 border border-orange-200',
};

const statusBadgeClass: Record<DecisionStatus, string> = {
 pending: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
 acknowledged:'bg-blue-100 text-blue-700',
 ordered: 'bg-indigo-100 text-indigo-700',
 in_transit: 'bg-violet-100 text-violet-700',
 received: 'bg-green-100 text-green-700',
 ignored: 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
 superseded: 'bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-500',
};

function badgeLabel(decision: string): string {
 const labels: Record<string, string> = {
 order: 'Order Now',
 watch: 'Watch',
 hold: 'Hold',
 order_budget_blocked: 'Budget Blocked',
 };
 return labels[decision] ?? decision;
}

// Urgency reflects "how soon must we act." Recommendation says "should we
// order?" (driven by reorder-point math); Urgency answers "how much time
// before stockout?" (driven by buffer = days of cover − lead time).
//
// Unified scale across every recommendation type — the same words mean the
// same thing whether the row is Order Now, Watch, or Hold:
//
//   buffer < 0  → Severe  (already past lead time — will stock out)
//   buffer < 3  → High    (under 3 days of slack)
//   buffer < 7  → Medium  (under 7 days of slack)
//   buffer ≥ 7  → Low     (a week or more of runway)
function urgency(row: DecisionRow): { label: string; classes: string } {
 const buffer = row.days_of_cover - row.lead_time_days;

 if (buffer < 0) return { label: 'Severe', classes: 'text-red-600 dark:text-red-400 font-semibold' };
 if (buffer < 3) return { label: 'High',   classes: 'text-orange-600 dark:text-orange-400 font-semibold' };
 if (buffer < 7) return { label: 'Medium', classes: 'text-amber-600 dark:text-amber-400 font-semibold' };
 return                  { label: 'Low',    classes: 'text-green-600 dark:text-green-400 font-medium' };
}

function formatDemand(val: number): string {
 return val < 1 ? val.toFixed(2) : Math.round(val).toString();
}

function formatLastRun(lastRun: LastRun | null): string {
 if (!lastRun) return '';
 const date = new Date(lastRun.run_at);
 const diffMs = Date.now() - date.getTime();
 const diffMins = Math.floor(diffMs / 60000);
 if (diffMins < 1) return 'just now';
 if (diffMins < 60) return `${diffMins}m ago`;
 const diffHrs = Math.floor(diffMins / 60);
 if (diffHrs < 24) return `${diffHrs}h ago`;
 return `${Math.floor(diffHrs / 24)}d ago`;
}

function relativeTime(iso: string): string {
 const date = new Date(iso);
 const diffMs = Date.now() - date.getTime();
 const diffMins = Math.floor(diffMs / 60000);
 if (diffMins < 1) return 'just now';
 if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
 const diffHrs = Math.floor(diffMins / 60);
 if (diffHrs < 24) return `${diffHrs} hour${diffHrs !== 1 ? 's' : ''} ago`;
 return `${Math.floor(diffHrs / 24)} day${Math.floor(diffHrs / 24) !== 1 ? 's' : ''} ago`;
}

function statusLabel(s: DecisionStatus): string {
 const labels: Record<DecisionStatus, string> = {
 pending: 'Pending',
 acknowledged:'Acknowledged',
 ordered: 'Ordered',
 in_transit: 'In Transit',
 received: 'Received',
 ignored: 'Ignored',
 superseded: 'Superseded',
 };
 return labels[s] ?? s;
}

// "Active Recommendations" surfaces every row the engine flagged for review.
// All recommendations the engine flagged for review — order/budget_blocked
// (action-required) plus watch (monitor). Three exclusive tiers (Order Now,
// Order Soon, Watchlist) are computed below from this same set so the
// section badges and KPI cards stay aligned.
const orderRecommendations = computed(() =>
 props.decisions.filter(d =>
 d.decision === 'order' || d.decision === 'order_budget_blocked'
 )
);
const watchRecommendations = computed(() =>
 props.decisions.filter(d => d.decision === 'watch')
);
const allRecommendations = computed(() => [
 ...orderRecommendations.value,
 ...watchRecommendations.value,
]);

const overviewControls = useTableControls(
 () => allRecommendations.value,
 ['sku_code', 'sku_name'] as (keyof DecisionRow)[],
);

// Three exclusive tiers — counts equal the KPI cards above and the
// section badges below. Search/sort still apply globally; each group
// derives from `overviewControls.filtered.value` so a search for "BENCH"
// filters all three sections at once.
//
// Order Now  = order/budget_blocked AND cover < lead time (urgent — act today)
// Order Soon = order/budget_blocked AND cover >= lead time (planned — act this week)
// Watchlist  = watch decisions (monitor only)
const isOrderNowRow = (row: DecisionRow): boolean => {
 const isOrder = row.decision === 'order' || row.decision === 'order_budget_blocked';
 return isOrder && row.days_of_cover < row.lead_time_days;
};

const orderNowRows = computed(() =>
 overviewControls.filtered.value.filter(isOrderNowRow)
);

const orderSoonRows = computed(() => overviewControls.filtered.value.filter(row => {
 const isOrder = row.decision === 'order' || row.decision === 'order_budget_blocked';
 return isOrder && !isOrderNowRow(row);
}));

const watchlistRows = computed(() =>
 overviewControls.filtered.value.filter(row => row.decision === 'watch')
);

// Watchlist is collapsed by default — least urgent, biggest count, lowest
// payoff from auto-expanding. Order Now and Order Soon stay open so the
// action set is visible without a click.
type SectionKey = 'orderNow' | 'orderSoon' | 'watchlist';
const sectionsOpen = ref<Record<SectionKey, boolean>>({
 orderNow:  true,
 orderSoon: true,
 watchlist: false,
});

function toggleSection(key: SectionKey): void {
 sectionsOpen.value[key] = !sectionsOpen.value[key];
}

/**
 * KPI card click handler. KPI counts equal section counts exactly, so
 * clicking a card expands the matching section (in case it's collapsed)
 * and scrolls to it. Two anchors exist per section — one in the desktop
 * table tbody, one on the mobile card-list header — exactly one is
 * rendered at any viewport (the other is display:none). Pick whichever
 * is currently laid out so scrollIntoView lands correctly on both.
 *
 * When count = 0, the card is rendered as a non-interactive div, so this
 * is never called for empty tiers.
 */
function focusSection(key: SectionKey): void {
 sectionsOpen.value[key] = true;
 nextTick(() => {
 const desktop = document.getElementById(`section-${key}-anchor`);
 const mobile  = document.getElementById(`section-${key}-mobile-anchor`);
 const visible = (el: HTMLElement | null) => !!el && el.offsetParent !== null;
 const target  = visible(desktop) ? desktop : visible(mobile) ? mobile : (desktop ?? mobile);
 target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
 });
}

interface SectionMeta {
 key: SectionKey;
 label: string;
 rows: DecisionRow[];
 badgeClass: string;
 headerBg: string;
}

const sections = computed<SectionMeta[]>(() => [
 {
 key: 'orderNow',
 label: 'Order Now — Order Today',
 rows: orderNowRows.value,
 badgeClass: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 ring-red-200 dark:ring-red-800',
 headerBg: 'bg-red-50/60 dark:bg-red-900/20',
 },
 {
 key: 'orderSoon',
 label: 'Order Soon — Plan Ahead',
 rows: orderSoonRows.value,
 badgeClass: 'bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 ring-orange-200 dark:ring-orange-800',
 headerBg: 'bg-orange-50/40 dark:bg-orange-900/20',
 },
 {
 key: 'watchlist',
 label: 'Watchlist — Monitor Only',
 rows: watchlistRows.value,
 badgeClass: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 ring-amber-200 dark:ring-amber-800',
 headerBg: 'bg-amber-50/30 dark:bg-amber-900/15',
 },
]);

const engineRunning = ref(false);

function runEngine() {
 if (engineRunning.value) return;
 engineRunning.value = true;
 router.post('/engine/run', {}, {
 onFinish: () => { engineRunning.value = false; },
 });
}

// `router` import is still used for the Run Engine action; navigateToDecisions
// retired in favour of focusSection — KPI cards now expand and scroll to the
// matching section instead of leaving the page. Cleaner UX since the data is
// already on this page.
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />
 <div class="max-w-7xl mx-auto px-6 lg:px-8 py-8 space-y-6">

 <!-- Page heading -->
 <div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Dashboard</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Stock Replenishment Recommendations</p>
 </div>

 <!-- Stat cards: three exclusive tiers + a fleet stat. KPI counts
 equal the section badges below (no overlap). Clicking each
 active KPI expands the matching section and scrolls to it. -->
 <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
 <!-- Order Now (urgent: cover < lead time) -->
 <button
 v-if="stats.order_now > 0"
 @click="focusSection('orderNow')"
 class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-red-600 text-left hover:shadow-md transition-shadow duration-150 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
 :aria-label="`${stats.order_now} SKUs to order today — jump to Order Now section`"
 >
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Order Now</p>
 <p class="text-3xl font-bold text-red-600 mt-2 tabular-nums">{{ stats.order_now }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Order Today</p>
 </button>
 <div
 v-else
 class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-slate-300 dark:border-l-slate-600"
 >
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Order Now</p>
 <p class="text-3xl font-bold mt-2 tabular-nums text-slate-400 dark:text-slate-500">{{ stats.order_now }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Order Today</p>
 </div>

 <!-- Order Soon (planned: cover >= lead time) -->
 <button
 v-if="stats.order_soon > 0"
 @click="focusSection('orderSoon')"
 class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-orange-500 text-left hover:shadow-md transition-shadow duration-150 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2"
 :aria-label="`${stats.order_soon} SKUs to order this week — jump to Order Soon section`"
 >
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Order Soon</p>
 <p class="text-3xl font-bold text-orange-500 mt-2 tabular-nums">{{ stats.order_soon }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Plan Ahead</p>
 </button>
 <div
 v-else
 class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-slate-300 dark:border-l-slate-600"
 >
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Order Soon</p>
 <p class="text-3xl font-bold mt-2 tabular-nums text-slate-400 dark:text-slate-500">{{ stats.order_soon }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Plan Ahead</p>
 </div>

 <!-- Watchlist (monitor only) -->
 <button
 v-if="stats.watchlist > 0"
 @click="focusSection('watchlist')"
 class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-amber-400 text-left hover:shadow-md transition-shadow duration-150 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
 :aria-label="`${stats.watchlist} SKUs to monitor — jump to Watchlist section`"
 >
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Watchlist</p>
 <p class="text-3xl font-bold text-amber-500 mt-2 tabular-nums">{{ stats.watchlist }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Monitor Only</p>
 </button>
 <div
 v-else
 class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-slate-300 dark:border-l-slate-600"
 >
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Watchlist</p>
 <p class="text-3xl font-bold mt-2 tabular-nums text-slate-400 dark:text-slate-500">{{ stats.watchlist }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Monitor Only</p>
 </div>

 <!-- Avg Days of Cover (fleet stat — not a tier) -->
 <div class="bg-white dark:bg-slate-900 rounded-xl p-5 shadow-sm border border-slate-200 dark:border-slate-700 border-l-4 border-l-blue-500">
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Avg Days of Cover</p>
 <GlossaryTip term="avg_days_cover" />
 </div>
 <p class="text-3xl font-bold text-slate-800 dark:text-slate-100 mt-2 tabular-nums">{{ stats.avg_days_cover }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">across all SKUs</p>
 </div>
 </div>

 <!-- Run Engine + last run -->
 <div class="flex items-center gap-4">
 <button
 @click="runEngine"
 :disabled="engineRunning"
 class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 active:bg-blue-800 transition-colors duration-150 cursor-pointer shadow-sm disabled:opacity-60 disabled:cursor-not-allowed"
 :aria-label="engineRunning ? 'Engine running…' : 'Run inventory engine'"
 >
 <svg
 class="w-4 h-4"
 :class="{ 'animate-spin': engineRunning }"
 fill="none" stroke="currentColor" viewBox="0 0 24 24"
 >
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
 </svg>
 {{ engineRunning ? 'Running…' : 'Run Engine' }}
 </button>
 <p v-if="props.lastRun" class="text-xs text-slate-400 dark:text-slate-500">
 Last run: {{ formatLastRun(props.lastRun) }} · {{ props.lastRun.decisions_count }} decisions
 <span v-if="props.lastRun.status === 'failed'" class="text-red-500 ml-1">· failed</span>
 </p>
 </div>

 <!-- Dead stock alert -->
 <div
 v-if="deadStock.length > 0"
 class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4"
 >
 <div class="flex items-center gap-2 mb-2">
 <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
 </svg>
 <span class="text-sm font-semibold text-amber-800">Dead Stock — No sales in 30 days</span>
 </div>
 <div class="flex flex-wrap gap-2">
 <span
 v-for="sku in deadStock"
 :key="sku.id"
 class="text-xs bg-amber-100 text-amber-800 px-2.5 py-1 rounded-full ring-1 ring-amber-200"
 >
 {{ sku.name }} ({{ sku.current_stock }} units)
 </span>
 </div>
 </div>

 <!-- Active Recommendations -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-2.5">
 <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Active Recommendations</span>
 <span
 v-if="orderRecommendations.length > 0"
 class="text-xs font-bold bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 px-2 py-0.5 rounded-full ring-1 ring-red-200 dark:ring-red-800"
 :title="`${orderRecommendations.length} SKU${orderRecommendations.length === 1 ? '' : 's'} to order — Order Now plus Order Soon`"
 >
 {{ orderRecommendations.length }} To Order
 </span>
 <span
 v-if="watchRecommendations.length > 0"
 class="text-xs font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 px-2 py-0.5 rounded-full ring-1 ring-amber-200 dark:ring-amber-800"
 :title="`${watchRecommendations.length} SKU${watchRecommendations.length === 1 ? '' : 's'} on the watchlist`"
 >
 {{ watchRecommendations.length }} Watchlist
 </span>
 </div>

 <EmptyState
 v-if="allRecommendations.length === 0"
 dense
 title="All stock levels healthy"
 description="Nothing to action today."
 >
 <template #icon>
 <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-green-50">
 <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
 </svg>
 </div>
 </template>
 </EmptyState>

 <template v-else>
 <TableControls
 :search="overviewControls.search.value"
 :active-filters="overviewControls.activeFilters.value"
 :result-count="overviewControls.resultCount.value"
 :total-count="overviewControls.totalCount.value"
 @update:search="overviewControls.search.value = $event"
 @update:filter="overviewControls.setFilter($event.key, $event.value)"
 @clear="overviewControls.clearAll()"
 />
 <!-- Desktop table view (≥768px) -->
 <div class="hidden md:block overflow-x-auto">
 <table class="w-full text-sm">
 <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-100 dark:border-slate-800">
 <tr>
 <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">SKU</th>
 <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center gap-1">Recommendation <GlossaryTip term="recommendation" /></div>
 </th>
 <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center gap-1">Urgency <GlossaryTip term="urgency" /></div>
 </th>
 <th
 class="px-5 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="overviewControls.setSort('effective_position')"
 >
 <div class="flex items-center justify-end gap-1">
 Eff. Position
 <GlossaryTip term="effective_position" />
 <span v-if="overviewControls.sortKey.value === 'effective_position'" class="text-slate-400 dark:text-slate-500">
 {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th
 class="px-5 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="overviewControls.setSort('forecast_demand')"
 >
 <div class="flex items-center justify-end gap-1">
 Demand / Day
 <GlossaryTip term="demand_rate" />
 <span v-if="overviewControls.sortKey.value === 'forecast_demand'" class="text-slate-400 dark:text-slate-500">
 {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th
 class="px-5 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="overviewControls.setSort('days_of_cover')"
 >
 <div class="flex items-center justify-end gap-1">
 Days Cover
 <GlossaryTip term="days_cover" />
 <span v-if="overviewControls.sortKey.value === 'days_of_cover'" class="text-slate-400 dark:text-slate-500">
 {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th
 class="px-5 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="overviewControls.setSort('lead_time_days')"
 >
 <div class="flex items-center justify-end gap-1">
 Lead Time
 <GlossaryTip term="lead_time" />
 <span v-if="overviewControls.sortKey.value === 'lead_time_days'" class="text-slate-400 dark:text-slate-500">
 {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th
 class="px-5 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="overviewControls.setSort('constrained_qty')"
 >
 <div class="flex items-center justify-end gap-1">
 Rec. Qty
 <GlossaryTip term="constrained_qty" />
 <span v-if="overviewControls.sortKey.value === 'constrained_qty'" class="text-slate-400 dark:text-slate-500">
 {{ overviewControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Action</th>
 </tr>
 </thead>
 <!-- Three-tier grouping: act today / act soon / monitor.
 Search + sort apply across all three; row tinting keeps
 the inline pink for any row whose cover is below lead time. -->
 <template
 v-for="section in sections"
 :key="section.key"
 >
 <tbody v-if="section.rows.length > 0" class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr :class="section.headerBg" :id="`section-${section.key}-anchor`">
 <td colspan="9" class="px-3 py-2">
 <button
 @click="toggleSection(section.key)"
 class="w-full flex items-center gap-2 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 hover:text-slate-900 dark:hover:text-slate-100 transition-colors min-h-[36px] px-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 :aria-expanded="sectionsOpen[section.key]"
 :aria-controls="`section-${section.key}`"
 >
 <svg
 class="w-3.5 h-3.5 transition-transform duration-150 text-slate-500 dark:text-slate-400"
 :class="sectionsOpen[section.key] ? 'rotate-90' : ''"
 viewBox="0 0 12 12"
 fill="none"
 stroke="currentColor"
 stroke-width="2"
 stroke-linecap="round"
 stroke-linejoin="round"
 aria-hidden="true"
 ><path d="M4 2 L8 6 L4 10" /></svg>
 <span class="uppercase tracking-wide">{{ section.label }}</span>
 <span
 class="text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 tabular-nums"
 :class="section.badgeClass"
 >
 {{ section.rows.length }}
 </span>
 </button>
 </td>
 </tr>
 <template v-if="sectionsOpen[section.key]">
 <tr
 v-for="row in section.rows"
 :key="row.id"
 class="transition-colors duration-100"
 :class="row.days_of_cover < row.lead_time_days
 ? 'bg-red-50 dark:bg-red-900/20 hover:bg-red-100/60 dark:hover:bg-red-900/30'
 : 'hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800'"
 >
 <td class="px-5 py-3.5">
 <button
 @click="selectedSkuId = row.sku_id"
 class="text-left cursor-pointer"
 >
 <p class="font-semibold text-slate-800 dark:text-slate-100 text-sm hover:text-blue-600 transition-colors duration-100">{{ row.sku_name }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 font-mono mt-0.5">{{ row.sku_code }}</p>
 </button>
 </td>
 <td class="px-5 py-3.5">
 <DecisionPill :decision="row.decision" />
 </td>
 <td class="px-5 py-3.5">
 <span class="text-sm" :class="urgency(row).classes">{{ urgency(row).label }}</span>
 </td>
 <td class="px-5 py-3.5 text-right font-semibold text-slate-800 dark:text-slate-100 tabular-nums">{{ row.effective_position }}</td>
 <td class="px-5 py-3.5 text-right font-mono text-slate-600 dark:text-slate-300 tabular-nums">{{ formatDemand(row.forecast_demand) }}</td>
 <td class="px-5 py-3.5 text-right font-semibold tabular-nums" :class="urgency(row).classes">{{ row.days_of_cover }}</td>
 <td class="px-5 py-3.5 text-right text-slate-500 dark:text-slate-400 tabular-nums">{{ row.lead_time_days }}d</td>
 <td class="px-5 py-3.5 text-right font-semibold text-slate-800 dark:text-slate-100 tabular-nums">{{ row.constrained_qty }}</td>
 <td class="px-5 py-3.5 text-right">
 <Link
 :href="`/decisions?sku=${encodeURIComponent(row.sku_code)}`"
 class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline transition-colors"
 :title="`Act on ${row.sku_code} on the Recommendations page`"
 >
 Act
 <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
 </svg>
 </Link>
 </td>
 </tr>
 </template>
 </tbody>
 </template>
 <tbody v-if="overviewControls.filtered.value.length === 0">
 <tr>
 <td colspan="9" class="px-5 py-10 text-center">
 <p class="text-sm text-slate-500 dark:text-slate-400">No SKUs match your search.</p>
 </td>
 </tr>
 </tbody>
 </table>
 </div>

 <!-- Mobile card view (<768px) — table cells don't fit; cards
 surface the three highest-leverage numbers (Days Cover,
 Eff. Position, Demand/day) plus decision and urgency.
 Reuses sections + sectionsOpen + toggleSection so the
 expand/collapse state stays in sync if the user rotates
 the device. -->
 <div class="md:hidden p-3 space-y-3">
 <template
 v-for="section in sections"
 :key="`mobile-${section.key}`"
 >
 <div v-if="section.rows.length > 0" class="space-y-2" :id="`section-${section.key}-mobile-anchor`">
 <button
 @click="toggleSection(section.key)"
 class="w-full flex items-center gap-2 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 hover:text-slate-900 dark:hover:text-slate-100 transition-colors min-h-[44px] px-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 :class="section.headerBg"
 :aria-expanded="sectionsOpen[section.key]"
 :aria-controls="`section-mobile-${section.key}`"
 >
 <svg
 class="w-3.5 h-3.5 transition-transform duration-150 text-slate-500 dark:text-slate-400"
 :class="sectionsOpen[section.key] ? 'rotate-90' : ''"
 viewBox="0 0 12 12"
 fill="none"
 stroke="currentColor"
 stroke-width="2"
 stroke-linecap="round"
 stroke-linejoin="round"
 aria-hidden="true"
 ><path d="M4 2 L8 6 L4 10" /></svg>
 <span class="uppercase tracking-wide flex-1">{{ section.label }}</span>
 <span
 class="text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 tabular-nums"
 :class="section.badgeClass"
 >
 {{ section.rows.length }}
 </span>
 </button>

 <div
 v-if="sectionsOpen[section.key]"
 :id="`section-mobile-${section.key}`"
 class="space-y-2"
 >
 <article
 v-for="row in section.rows"
 :key="`mobile-${row.id}`"
 class="rounded-lg border p-3 transition-colors"
 :class="row.days_of_cover < row.lead_time_days
 ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20'
 : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900'"
 >
 <button
 @click="selectedSkuId = row.sku_id"
 class="w-full text-left mb-2 cursor-pointer"
 :aria-label="`View details for ${row.sku_name}`"
 >
 <p class="font-semibold text-slate-800 dark:text-slate-100 text-sm">{{ row.sku_name }}</p>
 <p class="text-xs text-slate-400 dark:text-slate-500 font-mono mt-0.5">{{ row.sku_code }}</p>
 </button>

 <div class="flex items-center gap-2 mb-3 flex-wrap">
 <DecisionPill :decision="row.decision" />
 <span class="text-xs" :class="urgency(row).classes">{{ urgency(row).label }}</span>
 </div>

 <div class="grid grid-cols-2 gap-x-3 gap-y-2 mb-3">
 <div>
 <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Days of Cover</p>
 <p class="text-sm font-semibold mt-0.5 tabular-nums" :class="urgency(row).classes">{{ row.days_of_cover }}</p>
 </div>
 <div>
 <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Rec. Qty</p>
 <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ row.constrained_qty }}</p>
 </div>
 <div>
 <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Eff. Pos.</p>
 <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ row.effective_position }}</p>
 </div>
 <div>
 <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Demand/Day</p>
 <p class="text-sm font-mono text-slate-600 dark:text-slate-300 mt-0.5 tabular-nums">{{ formatDemand(row.forecast_demand) }}</p>
 </div>
 </div>

 <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-slate-800">
 <span class="text-[10px] text-slate-400 dark:text-slate-500 tabular-nums">{{ row.lead_time_days }}d lead time</span>
 <Link
 :href="`/decisions?sku=${encodeURIComponent(row.sku_code)}`"
 class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline"
 >
 Act
 <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
 </svg>
 </Link>
 </div>
 </article>
 </div>
 </div>
 </template>

 <div
 v-if="overviewControls.filtered.value.length === 0"
 class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400"
 >
 No SKUs match your search.
 </div>
 </div>
 </template>
 </div>

 <!-- Recent Activity -->
 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
 <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Recent Activity</span>
 </div>
 <EmptyState
 v-if="recentActivity.length === 0"
 dense
 title="No recent activity"
 description="Run the engine to generate recommendations and they'll appear here."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
 </svg>
 </template>
 </EmptyState>
 <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
 <li
 v-for="item in recentActivity"
 :key="item.id"
 class="px-6 py-3.5 flex items-center gap-3"
 >
 <span class="font-mono text-xs bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded shrink-0">
 {{ item.sku_code }}
 </span>
 <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">{{ item.sku_name }}</span>
 <span class="text-sm text-slate-400 dark:text-slate-500">— status changed to</span>
 <span
 class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
 :class="statusBadgeClass[item.status]"
 >
 {{ statusLabel(item.status) }}
 </span>
 <span class="ml-auto text-xs text-slate-400 dark:text-slate-500 shrink-0">{{ relativeTime(item.updated_at) }}</span>
 </li>
 </ul>
 </div>

 </div>

 <SkuDetailDialog
 :sku-id="selectedSkuId"
 @close="selectedSkuId = null"
 @edit="(id) => router.visit(`/skus/${id}?edit=1`)"
 />
 </div>
</template>
