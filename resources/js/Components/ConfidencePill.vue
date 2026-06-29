<script setup lang="ts">
import { computed } from 'vue';

/**
 * Renders a forecast-confidence pill with a shape-based icon prefix so the
 * label is readable without relying on color alone. Used on the Decisions
 * page and Reports page.
 *
 * Shape mapping is deliberate:
 * high → up-triangle (▲, strong)
 * medium → square (■, neutral)
 * low → down-triangle (▼, weak)
 */
type Confidence = 'high' | 'medium' | 'low' | string;

const props = defineProps<{ confidence: Confidence }>();

const styling = computed<{ classes: string; label: string; ariaLabel: string }>(() => {
 switch (props.confidence) {
 case 'high':
 return {
 classes: 'bg-green-100 text-green-700',
 label: 'High',
 ariaLabel: 'Forecast confidence: high',
 };
 case 'medium':
 return {
 classes: 'bg-amber-100 text-amber-700',
 label: 'Medium',
 ariaLabel: 'Forecast confidence: medium',
 };
 case 'low':
 return {
 classes: 'bg-red-100 text-red-600',
 label: 'Low',
 ariaLabel: 'Forecast confidence: low',
 };
 default:
 return {
 classes: 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
 label: props.confidence,
 ariaLabel: `Forecast confidence: ${props.confidence}`,
 };
 }
});
</script>

<template>
 <span
 class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold"
 :class="styling.classes"
 :aria-label="styling.ariaLabel"
 role="status"
 >
 <svg
 v-if="confidence === 'high'"
 class="w-2 h-2"
 viewBox="0 0 10 10"
 fill="currentColor"
 aria-hidden="true"
 ><path d="M5 1 L9 8 L1 8 Z" /></svg>

 <svg
 v-else-if="confidence === 'medium'"
 class="w-2 h-2"
 viewBox="0 0 10 10"
 fill="currentColor"
 aria-hidden="true"
 ><rect x="1.5" y="1.5" width="7" height="7" /></svg>

 <svg
 v-else-if="confidence === 'low'"
 class="w-2 h-2"
 viewBox="0 0 10 10"
 fill="currentColor"
 aria-hidden="true"
 ><path d="M5 9 L9 2 L1 2 Z" /></svg>

 <span>{{ styling.label }}</span>
 </span>
</template>
