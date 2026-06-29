<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';

interface Alert {
 sku_code: string;
 sku_name: string;
 days_of_cover: number;
 lead_time_days: number;
}

const alerts = ref<Alert[]>([]);
const open = ref(false);

function toggle() {
 open.value = !open.value;
}

function clearAll() {
 alerts.value = [];
 open.value = false;
}

function handleClickOutside(e: MouseEvent) {
 const el = document.getElementById('notification-bell');
 if (el && !el.contains(e.target as Node)) {
 open.value = false;
 }
}

onMounted(() => {
 document.addEventListener('click', handleClickOutside);

 // Subscribe only if Echo is available (Reverb running)
 if (window.Echo) {
 window.Echo.private('inventory-alerts')
 .listen('.stock.alert', (e: { alerts: Alert[] }) => {
 alerts.value.unshift(...e.alerts);
 });
 }
});

onUnmounted(() => {
 document.removeEventListener('click', handleClickOutside);
 if (window.Echo) {
 window.Echo.leave('inventory-alerts');
 }
});
</script>

<template>
 <div id="notification-bell" class="relative">
 <button
 @click.stop="toggle"
 class="relative inline-flex items-center justify-center min-w-[44px] min-h-[44px] text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 dark:bg-slate-800 rounded-lg transition-colors duration-150 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 :aria-label="alerts.length > 0 ? `Notifications — ${alerts.length} alert${alerts.length === 1 ? '' : 's'}` : 'Notifications'"
 :aria-expanded="open"
 aria-haspopup="true"
 >
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
 d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
 </svg>
 <span
 v-if="alerts.length > 0"
 class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-red-500"
 aria-hidden="true"
 ></span>
 </button>

 <!-- Dropdown -->
 <div
 v-if="open"
 class="absolute right-0 top-full mt-2 w-80 bg-white dark:bg-slate-900 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 z-50 overflow-hidden"
 >
 <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
 <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Stock Alerts</span>
 <button
 v-if="alerts.length > 0"
 @click="clearAll"
 class="text-xs text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer transition-colors duration-150"
 >
 Clear all
 </button>
 </div>

 <div v-if="alerts.length === 0" class="px-4 py-6 text-center text-sm text-slate-400 dark:text-slate-500">
 No alerts — all stock levels healthy.
 </div>

 <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800 max-h-64 overflow-y-auto">
 <li v-for="(alert, i) in alerts" :key="i" class="px-4 py-3">
 <div class="flex items-start justify-between gap-2">
 <div>
 <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ alert.sku_name }}</p>
 <p class="text-xs font-mono text-slate-400 dark:text-slate-500 mt-0.5">{{ alert.sku_code }}</p>
 </div>
 <span class="text-xs font-semibold bg-red-100 text-red-700 px-2 py-0.5 rounded-full border border-red-200 shrink-0 mt-0.5">
 ORDER NOW
 </span>
 </div>
 <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5">
 {{ alert.days_of_cover }}d cover · {{ alert.lead_time_days }}d lead time
 </p>
 </li>
 </ul>
 </div>
 </div>
</template>
