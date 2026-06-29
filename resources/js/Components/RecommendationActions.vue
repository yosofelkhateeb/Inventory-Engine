<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import ActionInputModal from '@/Components/ActionInputModal.vue';

type DecisionStatus =
 | 'pending'
 | 'acknowledged'
 | 'ordered'
 | 'in_transit'
 | 'received'
 | 'ignored'
 | 'superseded';

type Action = 'acknowledge' | 'order' | 'in_transit' | 'receive' | 'ignore';

const props = defineProps<{
 decisionId: number;
 status: DecisionStatus;
 /** Engine-recommended quantity — used as the default for "Mark Ordered". */
 recommendedQty?: number | null;
 /** Operator-entered ordered quantity — used as the default for "Mark Received". */
 orderedQty?: number | null;
 /** SKU code shown in the input modal header. */
 skuCode?: string;
}>();

const emit = defineEmits<{
 (e: 'status-updated', payload: { id: number; status: DecisionStatus }): void;
}>();

const currentStatus = ref<DecisionStatus>(props.status);
const loading = ref<Action | null>(null);
const error = ref<string | null>(null);
const open = ref(false);
const triggerRef = ref<HTMLButtonElement | null>(null);
const menuRef = ref<HTMLDivElement | null>(null);

// Menu starts offscreen so the first render (used to measure size) is invisible.
const menuStyle = ref<Record<string, string>>({
 position: 'fixed',
 left: '-9999px',
 top: '-9999px',
 width: '11rem',
});

const ACTION_TO_STATUS: Record<Action, DecisionStatus> = {
 acknowledge: 'acknowledged',
 order:       'ordered',
 in_transit:  'in_transit',
 receive:     'received',
 ignore:      'ignored',
};

// Lifecycle ordering — received and ignored are both terminal at level 4.
// superseded sits outside the active flow and is engine-managed only.
const STATUS_ORDER: Record<string, number> = {
 pending:      0,
 acknowledged: 1,
 ordered:      2,
 in_transit:   3,
 received:     4,
 ignored:      4,
 superseded:   5,
};

const ACTION_OPTIONS: { action: Action; label: string }[] = [
 { action: 'acknowledge', label: 'Acknowledge' },
 { action: 'order',       label: 'Mark Ordered' },
 { action: 'in_transit',  label: 'Mark In Transit' },
 { action: 'receive',     label: 'Mark Received' },
 { action: 'ignore',      label: 'Ignore' },
];

const STATUS_LABEL: Record<DecisionStatus, string> = {
 pending:      'Pending',
 acknowledged: 'Acknowledged',
 ordered:      'Ordered',
 in_transit:   'In Transit',
 received:     'Received',
 ignored:      'Ignored',
 superseded:   'Superseded',
};

const STATUS_PILL_CLASSES: Record<DecisionStatus, string> = {
 pending:      'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 ring-1 ring-slate-200 dark:ring-slate-700',
 acknowledged: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 ring-1 ring-blue-200 dark:ring-blue-800',
 ordered:      'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 ring-1 ring-indigo-200 dark:ring-indigo-800',
 in_transit:   'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 ring-1 ring-violet-200 dark:ring-violet-800',
 received:     'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-200 dark:ring-emerald-800',
 ignored:      'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 ring-1 ring-slate-200 dark:ring-slate-700',
 superseded:   'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700',
};

function isBackward(from: DecisionStatus, to: DecisionStatus): boolean {
 return (STATUS_ORDER[to] ?? 99) < (STATUS_ORDER[from] ?? -1);
}

const options = computed(() =>
 ACTION_OPTIONS.map(opt => {
 const target = ACTION_TO_STATUS[opt.action];
 return {
 ...opt,
 target,
 isCurrent: target === currentStatus.value,
 isBackward: isBackward(currentStatus.value, target),
 };
 })
);

/**
 * Position the teleported menu relative to the trigger using fixed coords —
 * this escapes any ancestor's overflow:hidden / overflow-x:auto (e.g. table
 * containers) that would otherwise clip an absolutely-positioned dropdown.
 *
 * Prefer below-the-trigger; flip above when there isn't enough room.
 * Right-align to the trigger; clamp to viewport edges with an 8px margin.
 */
const GAP    = 4;
const MARGIN = 8;

function place(): void {
 const trigger = triggerRef.value;
 const menu    = menuRef.value;
 if (!trigger || !menu) return;

 const tr = trigger.getBoundingClientRect();
 const mr = menu.getBoundingClientRect();
 const vw = window.innerWidth;
 const vh = window.innerHeight;

 const spaceBelow = vh - tr.bottom - GAP;
 const spaceAbove = tr.top - GAP;

 const top = (spaceBelow >= mr.height || spaceBelow >= spaceAbove)
 ? tr.bottom + GAP
 : tr.top - GAP - mr.height;

 // Right-align to trigger, then clamp to viewport.
 let left = tr.right - mr.width;
 if (left < MARGIN) left = MARGIN;
 if (left + mr.width > vw - MARGIN) left = vw - mr.width - MARGIN;

 const clampedTop = Math.max(MARGIN, Math.min(top, vh - mr.height - MARGIN));

 menuStyle.value = {
 position: 'fixed',
 left:  `${left}px`,
 top:   `${clampedTop}px`,
 width: '11rem',
 };
}

async function toggle(): Promise<void> {
 if (loading.value) return;
 if (open.value) {
 closeMenu();
 return;
 }
 open.value = true;
 await nextTick();
 place();
}

function closeMenu(): void {
 open.value = false;
 menuStyle.value = { position: 'fixed', left: '-9999px', top: '-9999px', width: '11rem' };
}

function onReflow(): void {
 if (open.value) place();
}

// ─── Input modal state ─────────────────────────────────────────────────
// Order / Receive / Ignore each prompt for a single value (qty or reason)
// before the PATCH fires. The modal also surfaces the backward-transition
// warning when relevant — folding the old window.confirm into a single
// prompt instead of two stacked dialogs.
// The input modal only handles the three actions that need a value
// (qty for order/receive, reason for ignore). The dropdown wraps all five
// actions but routes the no-payload ones (acknowledge / in_transit) through
// trigger() directly without touching the modal.
type ModalAction = 'order' | 'receive' | 'ignore';

interface PendingAction {
 action: ModalAction;
 target: DecisionStatus;
 isBackward: boolean;
}

const inputModalOpen = ref(false);
const pendingAction = ref<PendingAction | null>(null);

const modalDefaultQty = computed<number | null>(() => {
 if (! pendingAction.value) return null;
 if (pendingAction.value.action === 'order') return props.recommendedQty ?? null;
 if (pendingAction.value.action === 'receive') return props.orderedQty ?? props.recommendedQty ?? null;
 return null;
});

async function select(opt: { action: Action; target: DecisionStatus; isCurrent: boolean; isBackward: boolean }): Promise<void> {
 if (opt.isCurrent || loading.value) {
 closeMenu();
 return;
 }

 closeMenu();

 // Order / Receive / Ignore route through the input modal — qty for the
 // first two, reason for the third. The modal handles the backward
 // warning internally.
 if (opt.action === 'order' || opt.action === 'receive' || opt.action === 'ignore') {
 pendingAction.value = {
 action: opt.action as ModalAction,
 target: opt.target,
 isBackward: opt.isBackward,
 };
 inputModalOpen.value = true;
 return;
 }

 // Acknowledge / In Transit have no payload. Backward moves still need
 // confirmation since the input modal doesn't apply.
 if (opt.isBackward) {
 const ok = window.confirm(
 `This will move the recommendation backwards from "${STATUS_LABEL[currentStatus.value]}" to "${STATUS_LABEL[opt.target]}". The change will be recorded in the audit history. Continue?`
 );
 if (! ok) return;
 }

 await trigger(opt.action);
}

function onModalClose() {
 inputModalOpen.value = false;
 pendingAction.value = null;
}

async function onModalConfirm(payload: { qty?: number; reason?: string }) {
 const pending = pendingAction.value;
 inputModalOpen.value = false;
 pendingAction.value = null;
 if (! pending) return;

 const extra: Record<string, string | number> = {};
 if (typeof payload.qty === 'number') extra.qty = payload.qty;
 if (typeof payload.reason === 'string') extra.reason = payload.reason;

 await trigger(pending.action, extra);
}

async function trigger(action: Action, extra: Record<string, string | number> = {}): Promise<void> {
 if (loading.value) return;

 const previous = currentStatus.value;
 error.value = null;
 loading.value = action;

 // optimistic
 currentStatus.value = ACTION_TO_STATUS[action] ?? previous;

 try {
 const response = await fetch(
 `/inventory-decisions/${props.decisionId}/status`,
 {
 method: 'PATCH',
 headers: {
 'Content-Type': 'application/json',
 'X-Requested-With': 'XMLHttpRequest',
 'X-XSRF-TOKEN': decodeURIComponent(
 document.cookie
 .split('; ')
 .find(c => c.startsWith('XSRF-TOKEN='))
 ?.split('=')[1] ?? ''
 ),
 },
 body: JSON.stringify({ action, ...extra }),
 }
 );

 if (! response.ok) {
 const data = await response.json().catch(() => ({}));
 throw new Error((data as { message?: string }).message ?? `Request failed (${response.status})`);
 }

 const data = await response.json() as { id: number; status: DecisionStatus };
 currentStatus.value = data.status;
 emit('status-updated', { id: data.id, status: data.status });
 } catch (err) {
 currentStatus.value = previous;
 error.value = err instanceof Error ? err.message : 'Unknown error';
 } finally {
 loading.value = null;
 }
}

function onDocumentMouseDown(e: MouseEvent): void {
 if (!open.value) return;
 const target = e.target as Node;
 if (triggerRef.value?.contains(target)) return;
 if (menuRef.value?.contains(target)) return;
 closeMenu();
}

function onKeydown(e: KeyboardEvent): void {
 if (e.key === 'Escape' && open.value) closeMenu();
}

onMounted(() => {
 document.addEventListener('mousedown', onDocumentMouseDown);
 document.addEventListener('keydown', onKeydown);
 // capture: true catches scrolls on any nested scrollable ancestor (e.g. tables).
 window.addEventListener('scroll', onReflow, { passive: true, capture: true });
 window.addEventListener('resize', onReflow, { passive: true });
});

onBeforeUnmount(() => {
 document.removeEventListener('mousedown', onDocumentMouseDown);
 document.removeEventListener('keydown', onKeydown);
 window.removeEventListener('scroll', onReflow, { capture: true } as EventListenerOptions);
 window.removeEventListener('resize', onReflow);
});
</script>

<template>
 <div class="inline-block">
 <button
 ref="triggerRef"
 type="button"
 :disabled="loading !== null"
 @click="toggle"
 class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold transition-colors disabled:opacity-60"
 :class="STATUS_PILL_CLASSES[currentStatus]"
 >
 <span
 v-if="loading"
 class="inline-block w-3 h-3 border-2 border-current border-t-transparent rounded-full animate-spin"
 />
 <span>{{ STATUS_LABEL[currentStatus] }}</span>
 <svg class="w-3 h-3 opacity-70" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
 <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" />
 </svg>
 </button>

 <Teleport to="body">
 <div
 v-if="open"
 ref="menuRef"
 :style="menuStyle"
 class="z-[60] rounded-md shadow-lg bg-white dark:bg-slate-900 ring-1 ring-slate-200 dark:ring-slate-700 py-1"
 role="menu"
 >
 <button
 v-for="opt in options"
 :key="opt.action"
 type="button"
 :disabled="opt.isCurrent"
 @click="select(opt)"
 class="w-full text-left px-3 py-1.5 text-xs flex items-center justify-between transition-colors disabled:cursor-default"
 :class="opt.isCurrent
 ? 'bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
 : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer'"
 >
 <span>{{ opt.label }}</span>
 <span v-if="opt.isCurrent" class="text-[10px] uppercase tracking-wide text-slate-400">current</span>
 <span v-else-if="opt.isBackward" class="text-[10px] text-amber-600 dark:text-amber-400" title="Backward transition — confirmation required">
 ↶ back
 </span>
 </button>
 </div>
 </Teleport>

 <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>

 <ActionInputModal
 :open="inputModalOpen"
 :action="pendingAction?.action ?? null"
 :default-qty="modalDefaultQty"
 :recommended-qty="recommendedQty ?? null"
 :ordered-qty="orderedQty ?? null"
 :is-backward="pendingAction?.isBackward ?? false"
 :current-status-label="STATUS_LABEL[currentStatus]"
 :target-status-label="pendingAction ? STATUS_LABEL[pendingAction.target] : ''"
 :sku-code="skuCode"
 @close="onModalClose"
 @confirm="onModalConfirm"
 />
 </div>
</template>
