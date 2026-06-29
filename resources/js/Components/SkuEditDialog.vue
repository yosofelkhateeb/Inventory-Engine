<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

interface SupplierOption {
 id: number;
 name: string;
}

interface SkuFormShape {
 id: number;
 name: string;
 sku_code: string;
 category: 'equipment' | 'accessory' | 'bundle';
 supplier_id: number;
 lead_time_days: number;
 moq: number;
 unit_cost_sar: number;
 abc_class: string | null;
 xyz_class: string | null;
 safety_stock_multiplier_override: number | null;
}

const props = defineProps<{
 open: boolean;
 sku: SkuFormShape | null;
 suppliers: SupplierOption[];
}>();

const emit = defineEmits<{
 (e: 'close'): void;
 (e: 'saved'): void;
}>();

const form = useForm({
 name: '',
 category: 'accessory' as 'equipment' | 'accessory' | 'bundle',
 supplier_id: 0 as number,
 lead_time_days: 0,
 moq: 1,
 unit_cost_sar: 0,
 safety_stock_multiplier_override: null as number | null,
});

// Hydrate the form whenever the dialog opens with a fresh SKU.
watch(
 () => [props.open, props.sku?.id],
 () => {
 if (props.open && props.sku) {
 form.name = props.sku.name;
 form.category = props.sku.category;
 form.supplier_id = props.sku.supplier_id;
 form.lead_time_days = props.sku.lead_time_days;
 form.moq = props.sku.moq;
 form.unit_cost_sar = Number(props.sku.unit_cost_sar);
 form.safety_stock_multiplier_override = props.sku.safety_stock_multiplier_override;
 form.clearErrors();
 }
 },
 { immediate: true }
);

function close() {
 if (form.processing) return;
 emit('close');
}

function submit() {
 if (! props.sku) return;
 form.patch(`/skus/${props.sku.id}`, {
 preserveScroll: true,
 onSuccess: () => {
 emit('saved');
 emit('close');
 },
 });
}

function clearOverride() {
 form.safety_stock_multiplier_override = null;
}

function onKeydown(e: KeyboardEvent) {
 if (e.key === 'Escape' && props.open) close();
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
 <Teleport to="body">
 <div
 v-if="open && sku"
 class="fixed inset-0 z-50 flex items-center justify-center p-4"
 role="dialog"
 aria-modal="true"
 aria-labelledby="sku-edit-title"
 >
 <div class="absolute inset-0 bg-black/40" @click="close" />

 <div class="relative z-10 w-full max-w-lg bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden max-h-[90vh] flex flex-col">

 <!-- Header -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
 <div class="min-w-0">
 <h2 id="sku-edit-title" class="text-base font-bold text-slate-800 dark:text-slate-100 truncate">Edit SKU</h2>
 <p class="text-xs text-slate-500 dark:text-slate-400 font-mono mt-0.5">{{ sku.sku_code }}</p>
 </div>
 <button
 type="button"
 @click="close"
 class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors cursor-pointer"
 aria-label="Close"
 >
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
 </svg>
 </button>
 </div>

 <!-- Body -->
 <form @submit.prevent="submit" class="flex-1 overflow-y-auto px-5 py-4 space-y-4">

 <!-- Name -->
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Name</label>
 <input
 v-model="form.name"
 type="text"
 maxlength="255"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.name }"
 />
 <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
 </div>

 <!-- Category + Supplier -->
 <div class="grid grid-cols-2 gap-3">
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Category</label>
 <select
 v-model="form.category"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.category }"
 >
 <option value="equipment">Equipment</option>
 <option value="accessory">Accessory</option>
 <option value="bundle">Bundle</option>
 </select>
 <p v-if="form.errors.category" class="mt-1 text-xs text-red-500">{{ form.errors.category }}</p>
 </div>
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Supplier</label>
 <select
 v-model.number="form.supplier_id"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.supplier_id }"
 >
 <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
 </select>
 <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-red-500">{{ form.errors.supplier_id }}</p>
 </div>
 </div>

 <!-- Lead time + MOQ + Unit cost -->
 <div class="grid grid-cols-3 gap-3">
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Lead Time (Days)</label>
 <input
 v-model.number="form.lead_time_days"
 type="number"
 min="0"
 max="365"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 tabular-nums"
 :class="{ 'border-red-400': form.errors.lead_time_days }"
 />
 <p v-if="form.errors.lead_time_days" class="mt-1 text-xs text-red-500">{{ form.errors.lead_time_days }}</p>
 </div>
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">MOQ</label>
 <input
 v-model.number="form.moq"
 type="number"
 min="1"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 tabular-nums"
 :class="{ 'border-red-400': form.errors.moq }"
 />
 <p v-if="form.errors.moq" class="mt-1 text-xs text-red-500">{{ form.errors.moq }}</p>
 </div>
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Unit Cost (SAR)</label>
 <input
 v-model.number="form.unit_cost_sar"
 type="number"
 min="0"
 step="0.01"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 tabular-nums"
 :class="{ 'border-red-400': form.errors.unit_cost_sar }"
 />
 <p v-if="form.errors.unit_cost_sar" class="mt-1 text-xs text-red-500">{{ form.errors.unit_cost_sar }}</p>
 </div>
 </div>

 <!-- Engine-controlled (read-only) -->
 <div class="rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5">
 <p class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
 Engine-Controlled
 </p>
 <div class="flex items-center gap-3 text-sm">
 <div>
 <span class="text-xs text-slate-500 dark:text-slate-400 mr-1">ABC·XYZ:</span>
 <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">
 {{ sku.abc_class && sku.xyz_class ? `${sku.abc_class}·${sku.xyz_class}` : '—' }}
 </span>
 </div>
 <span class="text-slate-300 dark:text-slate-600">|</span>
 <p class="text-xs text-slate-500 dark:text-slate-400">
 Recomputed each engine run from sales history
 </p>
 </div>
 </div>

 <!-- Safety-stock override -->
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
 Safety Stock Multiplier Override
 <span class="text-slate-400 dark:text-slate-500 font-normal">(optional)</span>
 </label>
 <div class="flex items-center gap-2">
 <input
 v-model.number="form.safety_stock_multiplier_override"
 type="number"
 min="0.1"
 max="5.0"
 step="0.05"
 placeholder="—"
 class="flex-1 px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 tabular-nums"
 :class="{ 'border-red-400': form.errors.safety_stock_multiplier_override }"
 />
 <button
 type="button"
 @click="clearOverride"
 :disabled="form.safety_stock_multiplier_override === null"
 class="px-3 py-2 text-xs font-medium text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-300 dark:border-slate-600 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
 >
 Clear
 </button>
 </div>
 <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
 Leave blank to use the engine's ABC·XYZ-derived multiplier. Set a value (e.g. 1.30) to override for this SKU only — typical range 0.5–2.5.
 </p>
 <p v-if="form.errors.safety_stock_multiplier_override" class="mt-1 text-xs text-red-500">{{ form.errors.safety_stock_multiplier_override }}</p>
 </div>

 </form>

 <!-- Footer -->
 <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2 bg-slate-50 dark:bg-slate-800 shrink-0">
 <button
 type="button"
 @click="close"
 :disabled="form.processing"
 class="px-4 py-2 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 text-sm font-medium rounded-md transition-colors disabled:opacity-50 cursor-pointer"
 >
 Cancel
 </button>
 <button
 type="button"
 :disabled="form.processing || ! form.isDirty"
 @click="submit"
 class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
 >
 <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
 </svg>
 Save Changes
 </button>
 </div>

 </div>
 </div>
 </Teleport>
</template>
