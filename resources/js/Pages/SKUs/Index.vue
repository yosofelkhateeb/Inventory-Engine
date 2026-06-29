<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppHeader from '@/Components/AppHeader.vue';
import SkuDetailDialog from '@/Components/SkuDetailDialog.vue';
import SkuEditDialog from '@/Components/SkuEditDialog.vue';
import TableControls from '@/Components/TableControls.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DecisionPill from '@/Components/DecisionPill.vue';
import { useTableControls, type FilterDefinition } from '@/composables/useTableControls';

interface SkuRow {
 id: number;
 sku_code: string;
 name: string;
 category: 'equipment' | 'accessory' | 'bundle';
 current_stock: number;
 effective_position: number;
 unit_cost_sar: number;
 moq: number;
 lead_time_days: number;
 supplier_id: number;
 supplier_name: string;
 latest_decision: 'order' | 'watch' | 'hold' | 'order_budget_blocked' | null;
 days_of_cover: number | null;
 abc_class: 'A' | 'B' | 'C' | null;
 xyz_class: 'X' | 'Y' | 'Z' | null;
 safety_stock_multiplier_override: number | null;
}

interface SupplierOption {
 id: number;
 name: string;
}

const props = defineProps<{ skus: SkuRow[]; suppliers: SupplierOption[] }>();

const selectedSkuId = ref<number | null>(null);
const editingSkuId = ref<number | null>(null);
const editingSku = computed(() => props.skus.find(s => s.id === editingSkuId.value) ?? null);

function openEdit(id: number) { editingSkuId.value = id; }
function closeEdit() { editingSkuId.value = null; }
function onSkuSaved() { router.reload({ only: ['skus'], preserveScroll: true, preserveState: true }); }

const badgeClass: Record<string, string> = {
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

function abcBadgeClass(abc: string | null): string {
 if (abc === 'A') return 'bg-red-100 text-red-700';
 if (abc === 'B') return 'bg-amber-100 text-amber-700';
 return 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300';
}

function xyzBadgeStyle(xyz: string | null): string {
 if (xyz === 'X') return 'opacity-100';
 if (xyz === 'Y') return 'opacity-75';
 return 'opacity-50 ring-1 ring-current';
}

const skuControls = useTableControls(
 () => props.skus,
 ['sku_code', 'name', 'supplier_name'] as (keyof SkuRow)[],
);

const skuFilters = computed<FilterDefinition[]>(() => {
 const suppliers = [...new Set(props.skus.map(s => s.supplier_name).filter(Boolean))]
 .sort()
 .map(s => ({ value: s, label: s }));
 return [
 {
 key: 'latest_decision',
 label: 'Recommendation',
 options: [
 { value: 'order', label: 'Order Now' },
 { value: 'watch', label: 'Watch' },
 { value: 'hold', label: 'Hold' },
 { value: 'order_budget_blocked', label: 'Budget Blocked' },
 ],
 },
 {
 key: 'abc_class',
 label: 'ABC Class',
 options: [
 { value: 'A', label: 'A' },
 { value: 'B', label: 'B' },
 { value: 'C', label: 'C' },
 ],
 },
 {
 key: 'supplier_name',
 label: 'Supplier',
 options: suppliers,
 },
 ];
});
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />
 <div class="max-w-7xl mx-auto px-6 lg:px-8 py-8">

 <div class="mb-6">
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">SKU Catalogue</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
 <template v-if="skuControls.resultCount.value !== skuControls.totalCount.value">
 {{ skuControls.resultCount.value }} of {{ skuControls.totalCount.value }} products
 </template>
 <template v-else>{{ skuControls.totalCount.value }} products</template>
 </p>
 </div>

 <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
 <TableControls
 :search="skuControls.search.value"
 :filters="skuFilters"
 :active-filters="skuControls.activeFilters.value"
 :result-count="skuControls.resultCount.value"
 :total-count="skuControls.totalCount.value"
 @update:search="skuControls.search.value = $event"
 @update:filter="skuControls.setFilter($event.key, $event.value)"
 @clear="skuControls.clearAll()"
 />
 <div class="overflow-x-auto">
 <table class="w-full text-sm">
 <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-100 dark:border-slate-800">
 <tr>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">SKU</th>
 <th
 class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="skuControls.setSort('name')"
 >
 Name
 <span v-if="skuControls.sortKey.value === 'name'" class="ml-1 text-slate-400 dark:text-slate-500">
 {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Supplier</th>
 <th
 class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="skuControls.setSort('current_stock')"
 >
 <div class="flex items-center justify-end gap-1">
 On Hand
 <GlossaryTip term="on_hand" />
 <span v-if="skuControls.sortKey.value === 'current_stock'" class="text-slate-400 dark:text-slate-500">
 {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th
 class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="skuControls.setSort('days_of_cover')"
 >
 <div class="flex items-center justify-end gap-1">
 Days Cover
 <GlossaryTip term="days_cover" />
 <span v-if="skuControls.sortKey.value === 'days_of_cover'" class="text-slate-400 dark:text-slate-500">
 {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </div>
 </th>
 <th
 class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none"
 @click="skuControls.setSort('unit_cost_sar')"
 >
 Unit Cost (SAR)
 <span v-if="skuControls.sortKey.value === 'unit_cost_sar'" class="ml-1 text-slate-400 dark:text-slate-500">
 {{ skuControls.sortDir.value === 'asc' ? '↑' : '↓' }}
 </span>
 </th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center gap-1">Recommendation <GlossaryTip term="recommendation" /></div>
 </th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">
 <div class="flex items-center gap-1">Class <GlossaryTip term="abc_xyz_classification" /></div>
 </th>
 <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Actions</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr
 v-for="sku in skuControls.filtered.value"
 :key="sku.id"
 class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors duration-100"
 >
 <td class="px-4 py-3 whitespace-nowrap">
 <Link
 :href="`/skus/${sku.id}`"
 class="font-mono text-xs text-blue-600 hover:text-blue-800 hover:underline transition-colors duration-100"
 >
 {{ sku.sku_code }}
 </Link>
 </td>
 <td class="px-4 py-3 whitespace-nowrap">
 <Link
 :href="`/skus/${sku.id}`"
 class="font-semibold text-slate-800 dark:text-slate-100 hover:text-blue-600 transition-colors duration-100"
 >
 {{ sku.name }}
 </Link>
 </td>
 <td class="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ sku.supplier_name }}</td>
 <td class="px-4 py-3 text-right text-slate-700 dark:text-slate-200 tabular-nums">{{ sku.current_stock }}</td>
 <td class="px-4 py-3 text-right text-slate-700 dark:text-slate-200 tabular-nums">
 {{ sku.days_of_cover != null ? sku.days_of_cover : '—' }}
 </td>
 <td class="px-4 py-3 text-right text-slate-700 dark:text-slate-200 tabular-nums font-mono">
 {{ sku.unit_cost_sar.toFixed(2) }}
 </td>
 <td class="px-4 py-3">
 <DecisionPill v-if="sku.latest_decision" :decision="sku.latest_decision" />
 <span v-else class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">—</span>
 </td>
 <td class="px-4 py-3">
 <span
 v-if="sku.abc_class && sku.xyz_class"
 class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono"
 :class="[abcBadgeClass(sku.abc_class), xyzBadgeStyle(sku.xyz_class)]"
 >
 {{ sku.abc_class }}·{{ sku.xyz_class }}
 </span>
 <span v-else class="text-xs text-slate-300 dark:text-slate-600">—</span>
 </td>
 <td class="px-4 py-3 text-center">
 <div class="inline-flex items-center gap-1 justify-center">
 <button
 @click="selectedSkuId = sku.id"
 class="inline-flex items-center justify-center w-7 h-7 rounded text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 dark:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 transition-colors duration-100 cursor-pointer"
 :aria-label="`Preview ${sku.name}`"
 :title="`Preview ${sku.name}`"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
 </svg>
 </button>
 <button
 @click="openEdit(sku.id)"
 class="inline-flex items-center justify-center w-7 h-7 rounded text-slate-400 dark:text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-slate-100 dark:hover:bg-slate-800 dark:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 transition-colors duration-100 cursor-pointer"
 :aria-label="`Edit ${sku.name}`"
 :title="`Edit ${sku.name}`"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
 </svg>
 </button>
 </div>
 </td>
 </tr>
 <tr v-if="skuControls.filtered.value.length === 0">
 <td colspan="9">
 <EmptyState
 dense
 title="No SKUs match your filters"
 description="Try clearing a filter or adjusting the search term."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
 </svg>
 </template>
 </EmptyState>
 </td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>

 </div>

 <SkuDetailDialog :sku-id="selectedSkuId" @close="selectedSkuId = null" @edit="openEdit" />
 <SkuEditDialog
 :open="editingSkuId !== null"
 :sku="editingSku ? {
 id: editingSku.id,
 sku_code: editingSku.sku_code,
 name: editingSku.name,
 category: editingSku.category,
 supplier_id: editingSku.supplier_id,
 lead_time_days: editingSku.lead_time_days,
 moq: editingSku.moq,
 unit_cost_sar: editingSku.unit_cost_sar,
 abc_class: editingSku.abc_class,
 xyz_class: editingSku.xyz_class,
 safety_stock_multiplier_override: editingSku.safety_stock_multiplier_override,
 } : null"
 :suppliers="suppliers"
 @close="closeEdit"
 @saved="onSkuSaved"
 />
 </div>
</template>
