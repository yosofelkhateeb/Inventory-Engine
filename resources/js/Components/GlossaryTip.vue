<script setup lang="ts">
import { computed, ref, nextTick, onBeforeUnmount, onMounted } from 'vue';
import { getDefinition } from '@/composables/useGlossary';

/**
 * Inline help popover. Two modes:
 *
 * <GlossaryTip term="days_cover" />
 * — looks up the definition in the shared glossary by key.
 *
 * <GlossaryTip definition="..." aria-term="..." />
 * — uses the provided text directly. Use this for page-specific guidance
 * (e.g. Settings field help) where the explanation is local rather
 * than a globally re-used domain term.
 */
const props = defineProps<{
 term?: string;
 definition?: string;
 /** Override the screen-reader label when `definition` is passed inline. */
 ariaTerm?: string;
 label?: string;
}>();

const resolvedDefinition = computed<string | undefined>(() =>
 props.definition ?? (props.term ? getDefinition(props.term) : undefined)
);

const show = ref(false);
const ariaLabel = computed(() => {
 const seed = props.ariaTerm ?? props.term ?? 'this field';
 return `Help for ${seed.replace(/_/g, ' ')}`;
});

const triggerRef = ref<HTMLButtonElement | null>(null);
const tooltipRef = ref<HTMLDivElement | null>(null);

// Start offscreen so the first render (needed for measurement) isn't visible.
const tooltipStyle = ref<Record<string, string>>({
 position: 'fixed',
 left: '-9999px',
 top: '-9999px',
});

const GAP = 8; // gap between trigger and tooltip
const MARGIN = 8; // min distance from viewport edge

function place(): void {
 const trigger = triggerRef.value;
 const tooltip = tooltipRef.value;
 if (!trigger || !tooltip) return;

 const tr = trigger.getBoundingClientRect();
 const tt = tooltip.getBoundingClientRect();
 const vw = window.innerWidth;
 const vh = window.innerHeight;

 const spaceAbove = tr.top - GAP;
 const spaceBelow = vh - tr.bottom - GAP;
 const spaceRight = vw - tr.right - GAP;
 const spaceLeft = tr.left - GAP;

 let top: number;
 let left: number;

 // Prefer top → bottom → right → left. Fall back to the direction
 // with the most space if nothing fits comfortably.
 if (spaceAbove >= tt.height) {
 top = tr.top - GAP - tt.height;
 left = tr.left + tr.width / 2 - tt.width / 2;
 } else if (spaceBelow >= tt.height) {
 top = tr.bottom + GAP;
 left = tr.left + tr.width / 2 - tt.width / 2;
 } else if (spaceRight >= tt.width) {
 left = tr.right + GAP;
 top = tr.top + tr.height / 2 - tt.height / 2;
 } else if (spaceLeft >= tt.width) {
 left = tr.left - GAP - tt.width;
 top = tr.top + tr.height / 2 - tt.height / 2;
 } else {
 const verticalMax = Math.max(spaceAbove, spaceBelow);
 const horizontalMax = Math.max(spaceLeft, spaceRight);
 if (verticalMax >= horizontalMax) {
 top = spaceAbove >= spaceBelow ? MARGIN : tr.bottom + GAP;
 left = tr.left + tr.width / 2 - tt.width / 2;
 } else {
 left = spaceRight >= spaceLeft ? tr.right + GAP : tr.left - GAP - tt.width;
 top = tr.top + tr.height / 2 - tt.height / 2;
 }
 }

 left = Math.max(MARGIN, Math.min(left, vw - tt.width - MARGIN));
 top = Math.max(MARGIN, Math.min(top, vh - tt.height - MARGIN));

 tooltipStyle.value = {
 position: 'fixed',
 left: `${left}px`,
 top: `${top}px`,
 };
}

async function open(): Promise<void> {
 show.value = true;
 await nextTick();
 place();
}

function close(): void {
 show.value = false;
 tooltipStyle.value = { position: 'fixed', left: '-9999px', top: '-9999px' };
}

function onReflow(): void {
 if (show.value) place();
}

onMounted(() => {
 if (typeof window === 'undefined') return;
 // capture: true catches scrolls on any nested scrollable ancestor.
 window.addEventListener('scroll', onReflow, { passive: true, capture: true });
 window.addEventListener('resize', onReflow, { passive: true });
});

onBeforeUnmount(() => {
 if (typeof window === 'undefined') return;
 window.removeEventListener('scroll', onReflow, { capture: true } as AddEventListenerOptions);
 window.removeEventListener('resize', onReflow);
});
</script>

<template>
 <template v-if="resolvedDefinition">
 <span v-if="label">{{ label }}</span>
 <button
 ref="triggerRef"
 type="button"
 class="inline-flex items-center justify-center w-4 h-4 rounded-full text-xs font-semibold text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus:text-slate-600 transition-colors duration-100 cursor-help leading-none ml-0.5"
 :aria-label="ariaLabel"
 @mouseenter="open"
 @mouseleave="close"
 @focus="open"
 @blur="close"
 >
 ?
 </button>
 <Teleport to="body">
 <div
 v-if="show"
 ref="tooltipRef"
 role="tooltip"
 :style="tooltipStyle"
 class="z-[60] w-64 max-w-[260px] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg px-3 py-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed pointer-events-none"
 >
 {{ resolvedDefinition }}
 </div>
 </Teleport>
 </template>
</template>
