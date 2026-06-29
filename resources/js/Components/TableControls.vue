<script setup lang="ts">
import { computed } from 'vue';
import type { FilterDefinition } from '@/composables/useTableControls';

const props = defineProps<{
 search: string;
 filters?: FilterDefinition[];
 activeFilters: Record<string, string>;
 resultCount: number;
 totalCount: number;
}>();

const emit = defineEmits<{
 'update:search': [value: string];
 'update:filter': [payload: { key: string; value: string }];
 clear: [];
}>();

const hasActiveControls = computed(() =>
 props.search.trim() !== '' ||
 Object.values(props.activeFilters).some(v => v && v !== 'all'),
);
</script>

<template>
 <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900">
 <!-- Search input -->
 <div class="relative">
 <svg
 class="absolute left-2.5 top-2 w-3.5 h-3.5 text-slate-400 dark:text-slate-500 pointer-events-none"
 fill="none" stroke="currentColor" viewBox="0 0 24 24"
 >
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
 d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
 </svg>
 <input
 type="text"
 aria-label="Search"
 placeholder="Search..."
 :value="search"
 @input="emit('update:search', ($event.target as HTMLInputElement).value)"
 class="pl-8 pr-3 py-1.5 text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg w-48 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent placeholder:text-slate-400 dark:text-slate-500"
 />
 </div>

 <!-- Filter dropdowns — one per FilterDefinition -->
 <select
 v-for="filter in (filters ?? [])"
 :key="filter.key"
 :value="activeFilters[filter.key] ?? 'all'"
 :aria-label="filter.label"
 @change="emit('update:filter', { key: filter.key, value: ($event.target as HTMLSelectElement).value })"
 class="px-2.5 py-1.5 text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
 :class="activeFilters[filter.key] && activeFilters[filter.key] !== 'all'
 ? 'ring-2 ring-amber-400 border-amber-300'
 : ''"
 >
 <option value="all">{{ filter.label }}</option>
 <option v-for="opt in filter.options" :key="opt.value" :value="opt.value">
 {{ opt.label }}
 </option>
 </select>

 <div class="flex-1"></div>

 <!-- Result count + Clear all -->
 <span
 v-if="hasActiveControls || resultCount !== totalCount"
 class="text-xs text-slate-400 dark:text-slate-500"
 >
 Showing {{ resultCount }} of {{ totalCount }}
 </span>
 <button
 v-if="hasActiveControls"
 aria-label="Clear all filters and search"
 @click="emit('clear')"
 class="text-xs text-blue-600 hover:text-blue-800 hover:underline cursor-pointer"
 >
 Clear all
 </button>
 </div>
</template>
