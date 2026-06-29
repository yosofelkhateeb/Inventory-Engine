<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue';

interface SkuSummary {
 id: number;
 sku_code: string;
 name: string;
 supplier_name: string;
 unit_cost_sar: number;
 moq: number;
 lead_time_days: number;
 abc_class: 'A' | 'B' | 'C' | null;
 xyz_class: 'X' | 'Y' | 'Z' | null;
 current_stock: number;
 in_transit_qty: number;
 reserved_qty: number;
 effective_position: number;
 decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked' | null;
 constrained_qty: number | null;
 days_of_cover: number | null;
 reorder_point: number | null;
 forecast_demand: number | null;
 safety_stock: number | null;
 run_at: string | null;
}

const props = defineProps<{ skuId: number | null }>();
const emit = defineEmits<{ close: []; edit: [number] }>();

function onEditClick() {
 if (props.skuId === null) return;
 emit('edit', props.skuId);
 emit('close');
}

const loading = ref(false);
const error = ref(false);
const data = ref<SkuSummary | null>(null);

watch(() => props.skuId, async (id, _old, onCleanup) => {
 if (id === null) {
 data.value = null;
 return;
 }
 const controller = new AbortController();
 onCleanup(() => controller.abort());

 loading.value = true;
 error.value = false;
 try {
 const res = await fetch(`/skus/${id}/summary`, {
 headers: { Accept: 'application/json' },
 signal: controller.signal,
 });
 if (!res.ok) throw new Error();
 data.value = await res.json();
 } catch (e) {
 if (!(e instanceof DOMException && e.name === 'AbortError')) error.value = true;
 } finally {
 loading.value = false;
 }
});

function onKeydown(e: KeyboardEvent) {
 if (e.key === 'Escape') emit('close');
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

const decisionBadgeClass: Record<string, string> = {
 order: 'bg-red-100 text-red-700 border border-red-200',
 watch: 'bg-amber-100 text-amber-700 border border-amber-200',
 hold: 'bg-green-100 text-green-700 border border-green-200',
 order_budget_blocked: 'bg-orange-100 text-orange-700 border border-orange-200',
};

function decisionLabel(d: string | null): string {
 if (!d) return 'No Data';
 const map: Record<string, string> = {
 order: 'Order Now',
 watch: 'Watch',
 hold: 'Hold',
 order_budget_blocked: 'Budget Blocked',
 };
 return map[d] ?? d;
}

// Same unified Severe / High / Medium / Low scale as the dashboard.
// buffer = days_of_cover − lead_time_days.
function urgency(d: SkuSummary): { label: string; classes: string } {
 if (d.days_of_cover === null) return { label: '—', classes: 'text-slate-400 dark:text-slate-500' };
 const buffer = d.days_of_cover - d.lead_time_days;
 if (buffer < 0) return { label: 'Severe', classes: 'text-red-600 dark:text-red-400 font-semibold' };
 if (buffer < 3) return { label: 'High',   classes: 'text-orange-600 dark:text-orange-400 font-semibold' };
 if (buffer < 7) return { label: 'Medium', classes: 'text-amber-600 dark:text-amber-400 font-semibold' };
 return                  { label: 'Low',    classes: 'text-green-600 dark:text-green-400 font-medium' };
}

function abcBadgeClass(abc: string | null): string {
 if (abc === 'A') return 'bg-red-100 text-red-700';
 if (abc === 'B') return 'bg-amber-100 text-amber-700';
 return 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300';
}

function formatDemand(val: number | null): string {
 if (val === null) return '—';
 return val < 1 ? val.toFixed(2) : Math.round(val).toString();
}

/**
 * Buffer = days_of_cover − lead_time_days. Formatted with sign:
 *   '+5d'  — 5 days of slack before the next order needs to arrive
 *   '−3d'  — already 3 days past lead time (will stock out before reorder)
 *   '—'    — no cover data yet
 *
 * Uses U+2212 minus (en-dash style) instead of an ASCII hyphen so the
 * negative case reads as a math sign rather than a stray dash.
 */
function formatBuffer(d: SkuSummary): string {
 if (d.days_of_cover === null) return '—';
 const buffer = d.days_of_cover - d.lead_time_days;
 const rounded = Math.round(buffer * 10) / 10;
 if (rounded === 0)  return '0d';
 if (rounded > 0)    return `+${rounded}d`;
 return `−${Math.abs(rounded)}d`;
}
</script>

<template>
 <Teleport to="body">
 <div
 v-if="skuId !== null"
 class="fixed inset-0 z-50 flex items-center justify-center p-4"
 >
 <!-- Backdrop -->
 <div
 class="absolute inset-0 bg-black/40"
 @click="emit('close')"
 />

 <!-- Dialog panel -->
 <div
 role="dialog"
 aria-modal="true"
 aria-labelledby="sku-dialog-title"
 class="relative z-10 w-full max-w-lg bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden"
 >

 <!-- Loading -->
 <div v-if="loading" class="flex items-center justify-center h-48">
 <svg class="w-6 h-6 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
 </svg>
 </div>

 <!-- Error -->
 <div v-else-if="error" class="flex flex-col items-center justify-center h-48 gap-3">
 <p class="text-sm text-red-600">Failed to load SKU data.</p>
 <button
 class="text-xs text-blue-600 hover:underline cursor-pointer"
 @click="emit('close')"
 >
 Close
 </button>
 </div>

 <!-- Content -->
 <template v-else-if="data">

 <!-- Header -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-start justify-between gap-4">
 <div>
 <div class="flex items-center gap-2 flex-wrap">
 <span class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ data.sku_code }}</span>
 <span
 v-if="data.abc_class && data.xyz_class"
 class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold font-mono"
 :class="abcBadgeClass(data.abc_class)"
 >
 {{ data.abc_class }}·{{ data.xyz_class }}
 </span>
 </div>
 <h2 id="sku-dialog-title" class="text-base font-bold text-slate-800 dark:text-slate-100 mt-0.5">{{ data.name }}</h2>
 <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ data.supplier_name }}</p>
 </div>
 <div class="flex items-center gap-1.5 shrink-0">
 <button
 @click="onEditClick"
 class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-md px-2 py-1 transition-colors cursor-pointer"
 :aria-label="`Edit ${data.name}`"
 :title="`Edit ${data.name}`"
 >
 <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
 </svg>
 Edit
 </button>
 <button
 @click="emit('close')"
 class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors cursor-pointer ml-0.5"
 aria-label="Close dialog"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>
 </div>

 <!-- Inventory -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800">
 <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-3">Inventory</p>
 <div class="grid grid-cols-4 gap-4">
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">On Hand</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.current_stock }}</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">In Transit</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.in_transit_qty }}</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Reserved</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.reserved_qty }}</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Effective</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.effective_position }}</p>
 </div>
 </div>
 </div>

 <!-- Latest Recommendation -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800">
 <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-3">Latest Recommendation</p>

 <div v-if="!data.decision" class="text-xs text-slate-400 dark:text-slate-500 italic">
 No decision data yet. Run the engine first.
 </div>

 <template v-else>
 <div class="flex items-center gap-3 mb-4">
 <span
 class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold"
 :class="decisionBadgeClass[data.decision] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'"
 >
 {{ decisionLabel(data.decision) }}
 </span>
 <span class="text-sm" :class="urgency(data).classes">
 {{ urgency(data).label }}<span class="opacity-70 font-normal"> · {{ formatBuffer(data) }} buffer</span>
 </span>
 </div>

 <!-- 5-col grid: Days Cover, Lead Time, Buffer, Reorder Pt, Safety Stock.
 Buffer (= cover − lead time) is the explicit number behind the
 urgency label so users don't need to do the math. -->
 <div class="grid grid-cols-5 gap-3 mb-4">
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Days of Cover</p>
 <p class="text-sm font-bold tabular-nums mt-0.5" :class="urgency(data).classes">
 {{ data.days_of_cover ?? '—' }}
 </p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Lead Time</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.lead_time_days }}d</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Buffer</p>
 <p class="text-sm font-bold tabular-nums mt-0.5" :class="urgency(data).classes">{{ formatBuffer(data) }}</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Reorder Pt.</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.reorder_point ?? '—' }}</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Safety Stock</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.safety_stock ?? '—' }}</p>
 </div>
 </div>

 <div class="grid grid-cols-4 gap-4">
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Demand/day</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 font-mono tabular-nums">
 {{ formatDemand(data.forecast_demand) }}
 </p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Rec. Qty</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.constrained_qty ?? '—' }}</p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">Unit Cost</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 font-mono tabular-nums">
 {{ data.unit_cost_sar.toFixed(2) }} SAR
 </p>
 </div>
 <div>
 <p class="text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">MOQ</p>
 <p class="text-sm font-bold text-slate-800 dark:text-slate-100 mt-0.5 tabular-nums">{{ data.moq }}</p>
 </div>
 </div>
 </template>
 </div>

 <!-- Footer -->
 <div class="px-5 py-3 flex justify-end bg-slate-50 dark:bg-slate-800">
 <a
 :href="`/skus/${data.id}`"
 class="text-xs text-blue-600 hover:text-blue-800 hover:underline transition-colors font-medium"
 >
 View full detail →
 </a>
 </div>
 </template>
 </div>
 </div>
 </Teleport>
</template>
