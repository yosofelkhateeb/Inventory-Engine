<script setup lang="ts">
defineProps<{
 title: string;
 description?: string;
 dense?: boolean;
}>();
</script>

<template>
 <div class="text-center" :class="dense ? 'py-10 px-6' : 'py-14 px-6'">
 <div class="mb-3 inline-flex">
 <slot name="icon">
 <svg
 class="w-10 h-10 text-slate-300 dark:text-slate-600"
 fill="none"
 stroke="currentColor"
 viewBox="0 0 24 24"
 aria-hidden="true"
 >
 <path
 stroke-linecap="round"
 stroke-linejoin="round"
 stroke-width="1.5"
 d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-3.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-1.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 007.586 13H4"
 />
 </svg>
 </slot>
 </div>
 <p class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ title }}</p>
 <p v-if="description" class="text-xs text-slate-400 dark:text-slate-500 mt-1 max-w-sm mx-auto leading-relaxed">
 {{ description }}
 </p>
 <div v-if="$slots.action" class="mt-4">
 <slot name="action" />
 </div>
 </div>
</template>
