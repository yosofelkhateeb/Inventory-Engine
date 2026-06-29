<script setup lang="ts">
import { computed } from 'vue';

/**
 * Renders a recommendation pill with a shape-based icon prefix so that
 * color is not the only signal. The icon is decorative for sighted users
 * (color does most of the work) and the aria-label gives screen readers the
 * full meaning ("Recommendation: Order Now"). Used across Dashboard, the
 * Recommendations page, SKU detail, and the recent-activity feed.
 *
 * Shape mapping is deliberate:
 * order → solid up-triangle (▲, urgent / take action)
 * watch → hollow circle (○, monitor / hold attention)
 * hold → checkmark (✓, healthy / no action)
 * order_budget_blocked → warning sign (⚠, action wanted but blocked)
 */
type Decision = 'order' | 'watch' | 'hold' | 'order_budget_blocked' | string;

const props = withDefaults(defineProps<{
 decision: Decision;
 /** Compact = no border, smaller padding. Default = bordered pill. */
 compact?: boolean;
}>(), {
 compact: false,
});

const styling = computed<{ classes: string; label: string; ariaLabel: string }>(() => {
 switch (props.decision) {
 case 'order':
 return {
 classes: props.compact
 ? 'bg-red-100 text-red-700'
 : 'bg-red-100 text-red-700 border border-red-200',
 label: 'Order Now',
 ariaLabel: 'Recommendation: Order Now',
 };
 case 'watch':
 return {
 classes: props.compact
 ? 'bg-amber-100 text-amber-700'
 : 'bg-amber-100 text-amber-700 border border-amber-200',
 label: 'Watch',
 ariaLabel: 'Recommendation: Watch',
 };
 case 'hold':
 return {
 classes: props.compact
 ? 'bg-green-100 text-green-700'
 : 'bg-green-100 text-green-700 border border-green-200',
 label: 'Hold',
 ariaLabel: 'Recommendation: Hold',
 };
 case 'order_budget_blocked':
 return {
 classes: props.compact
 ? 'bg-orange-100 text-orange-700'
 : 'bg-orange-100 text-orange-700 border border-orange-200',
 label: 'Budget Blocked',
 ariaLabel: 'Recommendation: Budget Blocked — order recommended but over budget',
 };
 default:
 return {
 classes: 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
 label: props.decision,
 ariaLabel: `Recommendation: ${props.decision}`,
 };
 }
});
</script>

<template>
 <span
 class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap"
 :class="styling.classes"
 :aria-label="styling.ariaLabel"
 role="status"
 >
 <!-- Decorative icon — meaning is conveyed via aria-label above -->
 <svg
 v-if="decision === 'order'"
 class="w-2.5 h-2.5"
 viewBox="0 0 10 10"
 fill="currentColor"
 aria-hidden="true"
 ><path d="M5 1 L9 8 L1 8 Z" /></svg>

 <svg
 v-else-if="decision === 'watch'"
 class="w-2.5 h-2.5"
 viewBox="0 0 10 10"
 fill="none"
 stroke="currentColor"
 stroke-width="1.8"
 aria-hidden="true"
 ><circle cx="5" cy="5" r="3.2" /></svg>

 <svg
 v-else-if="decision === 'hold'"
 class="w-2.5 h-2.5"
 viewBox="0 0 10 10"
 fill="none"
 stroke="currentColor"
 stroke-width="2"
 stroke-linecap="round"
 stroke-linejoin="round"
 aria-hidden="true"
 ><path d="M2 5 L4.5 7.5 L8.5 3" /></svg>

 <svg
 v-else-if="decision === 'order_budget_blocked'"
 class="w-2.5 h-2.5"
 viewBox="0 0 10 10"
 fill="currentColor"
 aria-hidden="true"
 ><path d="M5 1 L9 8.5 L1 8.5 Z M5 4 L5 6 M5 7.2 L5 7.8" stroke="white" stroke-width="0.8" /></svg>

 <span>{{ styling.label }}</span>
 </span>
</template>
