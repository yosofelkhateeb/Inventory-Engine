<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type ActionKind = 'order' | 'receive' | 'ignore';

const props = defineProps<{
 open: boolean;
 action: ActionKind | null;
 /** Pre-fill for the qty input (recommended_qty for order, ordered_qty for receive). */
 defaultQty?: number | null;
 /** Recommended qty surfaced as helper text on the qty modes. */
 recommendedQty?: number | null;
 /** Existing ordered_qty surfaced as helper text on the receive mode. */
 orderedQty?: number | null;
 /** True when the operator picked a status earlier than the current one — surfaces a warning. */
 isBackward?: boolean;
 currentStatusLabel?: string;
 targetStatusLabel?: string;
 /** SKU code shown in the dialog header for context. */
 skuCode?: string;
}>();

const emit = defineEmits<{
 close: [];
 confirm: [{ qty?: number; reason?: string }];
}>();

const qtyValue = ref<number | null>(null);
const reasonValue = ref<string>('');
const error = ref<string | null>(null);
const inputRef = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);

watch(
 () => [props.open, props.action, props.defaultQty],
 async () => {
 if (! props.open) return;
 qtyValue.value = props.defaultQty ?? null;
 reasonValue.value = '';
 error.value = null;
 await nextTick();
 inputRef.value?.focus();
 if (inputRef.value instanceof HTMLInputElement) inputRef.value.select();
 },
 { immediate: true }
);

const title = computed(() => {
 if (props.action === 'order') return 'Mark as Ordered';
 if (props.action === 'receive') return 'Mark as Received';
 if (props.action === 'ignore') return 'Ignore Recommendation';
 return '';
});

const description = computed(() => {
 if (props.action === 'order') return 'Confirm the quantity actually ordered. The engine recommended a number; adjust if you ordered something different.';
 if (props.action === 'receive') return 'Confirm the quantity actually received. Adjust if the supplier shipped a different amount.';
 if (props.action === 'ignore') return 'Tell us why you\'re ignoring this recommendation. The reason is recorded in the audit log and feeds back into model calibration.';
 return '';
});

const qtyHelperText = computed(() => {
 if (props.action === 'order') {
 return props.recommendedQty !== null && props.recommendedQty !== undefined
 ? `Engine recommended ${props.recommendedQty} unit${props.recommendedQty === 1 ? '' : 's'}.`
 : 'No engine recommendation on record.';
 }
 if (props.action === 'receive') {
 if (props.orderedQty !== null && props.orderedQty !== undefined) {
 return `Ordered quantity: ${props.orderedQty}. Adjust if the shipment differed.`;
 }
 if (props.recommendedQty !== null && props.recommendedQty !== undefined) {
 return `No ordered qty on record. Engine recommended ${props.recommendedQty}.`;
 }
 return 'No prior quantity on record.';
 }
 return '';
});

function close() {
 emit('close');
}

function confirm() {
 if (props.action === 'ignore') {
 const trimmed = reasonValue.value.trim();
 if (trimmed === '') {
 error.value = 'A reason is required.';
 inputRef.value?.focus();
 return;
 }
 emit('confirm', { reason: trimmed });
 return;
 }

 if (props.action === 'order' || props.action === 'receive') {
 const qty = Number(qtyValue.value);
 if (! Number.isFinite(qty) || qty < 0) {
 error.value = 'Quantity must be zero or greater.';
 inputRef.value?.focus();
 return;
 }
 emit('confirm', { qty: Math.floor(qty) });
 return;
 }
}

function onKeydown(e: KeyboardEvent) {
 if (! props.open) return;
 if (e.key === 'Escape') close();
 if (e.key === 'Enter' && (e.target as HTMLElement | null)?.tagName !== 'TEXTAREA') {
 e.preventDefault();
 confirm();
 }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
 <Teleport to="body">
 <div
 v-if="open && action !== null"
 class="fixed inset-0 z-50 flex items-center justify-center p-4"
 role="dialog"
 aria-modal="true"
 aria-labelledby="action-input-title"
 >
 <div class="absolute inset-0 bg-black/40" @click="close" />

 <div class="relative z-10 w-full max-w-md bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden">

 <!-- Header -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800">
 <div class="flex items-start justify-between gap-3">
 <div class="min-w-0">
 <h2 id="action-input-title" class="text-base font-bold text-slate-800 dark:text-slate-100">{{ title }}</h2>
 <p v-if="skuCode" class="text-xs text-slate-500 dark:text-slate-400 font-mono mt-0.5">{{ skuCode }}</p>
 </div>
 <button
 type="button"
 @click="close"
 class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer shrink-0"
 aria-label="Close"
 >
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
 </svg>
 </button>
 </div>
 </div>

 <!-- Body -->
 <div class="px-5 py-4 space-y-3">

 <!-- Backward-transition warning -->
 <div
 v-if="isBackward"
 class="rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 px-3 py-2 flex items-start gap-2"
 >
 <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
 </svg>
 <p class="text-xs text-amber-800 dark:text-amber-200 leading-relaxed">
 This walks the recommendation backward from
 <span class="font-semibold">{{ currentStatusLabel }}</span>
 to <span class="font-semibold">{{ targetStatusLabel }}</span>.
 The change is recorded in the audit log.
 </p>
 </div>

 <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ description }}</p>

 <!-- Qty input — order / receive -->
 <div v-if="action === 'order' || action === 'receive'">
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
 {{ action === 'order' ? 'Qty Ordered' : 'Qty Received' }}
 </label>
 <input
 ref="inputRef"
 v-model.number="qtyValue"
 type="number"
 min="0"
 step="1"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 tabular-nums font-mono"
 :class="{ 'border-red-400': error }"
 />
 <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ qtyHelperText }}</p>
 </div>

 <!-- Reason textarea — ignore -->
 <div v-if="action === 'ignore'">
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
 Reason <span class="text-red-500">*</span>
 </label>
 <textarea
 ref="inputRef"
 v-model="reasonValue"
 rows="3"
 maxlength="500"
 placeholder="e.g. Stock arrived from a different supplier; budget reallocated; supplier on hold"
 class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': error }"
 />
 <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ reasonValue.length }} / 500 characters</p>
 </div>

 <p v-if="error" class="text-xs text-red-500">{{ error }}</p>

 </div>

 <!-- Footer -->
 <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 flex justify-end gap-2">
 <button
 type="button"
 @click="close"
 class="px-4 py-2 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 text-sm font-medium rounded-md transition-colors cursor-pointer"
 >
 Cancel
 </button>
 <button
 type="button"
 @click="confirm"
 class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition-colors cursor-pointer"
 >
 Confirm
 </button>
 </div>

 </div>
 </div>
 </Teleport>
</template>
