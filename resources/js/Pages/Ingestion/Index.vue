<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppHeader from '@/Components/AppHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';

interface IngestionRun {
 id: number;
 source: string;
 importer: string;
 status: 'pending' | 'running' | 'completed' | 'failed' | 'partial';
 rows_processed: number;
 rows_succeeded: number;
 rows_failed: number;
 error_log: Array<{ row?: number; errors?: string[]; error?: string }> | null;
 metadata: { entity?: string; file?: string; dry_run?: boolean } | null;
 started_at: string | null;
 completed_at: string | null;
}

interface ShopifyStatus {
 connected: boolean;
 shop_domain: string | null;
 last_sync_at: string | null;
 connected_at: string | null;
}

const props = defineProps<{
 runs: IngestionRun[];
 canUpload: boolean;
 shopify: ShopifyStatus | null;
}>();

type Entity = 'suppliers' | 'skus' | 'sales_history';

const sections: { entity: Entity; label: string; description: string }[] = [
 {
 entity: 'suppliers',
 label: 'Suppliers',
 description: 'Required first step. Import supplier names and lead times before connecting Shopify — Shopify does not provide supplier data.',
 },
 {
 entity: 'skus',
 label: 'SKUs (CSV override)',
 description: 'Manual SKU import. Prefer Shopify sync for ongoing SKU data; use this to correct or supplement Shopify data.',
 },
 {
 entity: 'sales_history',
 label: 'Sales History (CSV override)',
 description: 'Manual sales history import. Prefer Shopify sync; use this to backfill history not available via Shopify or to correct records.',
 },
];

// One form per entity
const forms = {
 suppliers: useForm<{ entity: Entity; file: File | null }>({ entity: 'suppliers', file: null }),
 skus: useForm<{ entity: Entity; file: File | null }>({ entity: 'skus', file: null }),
 sales_history: useForm<{ entity: Entity; file: File | null }>({ entity: 'sales_history', file: null }),
};

function onFileChange(entity: Entity, event: Event) {
 const input = event.target as HTMLInputElement;
 forms[entity].file = input.files?.[0] ?? null;
}

function submit(entity: Entity) {
 forms[entity].post('/ingestion/upload', {
 forceFormData: true,
 preserveScroll: true,
 onSuccess: () => {
 forms[entity].reset();
 const input = document.getElementById(`file-${entity}`) as HTMLInputElement | null;
 if (input) input.value = '';
 },
 });
}

function statusBadgeClass(status: IngestionRun['status']): string {
 const map: Record<IngestionRun['status'], string> = {
 pending: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
 running: 'bg-blue-100 text-blue-700',
 completed: 'bg-green-100 text-green-700',
 partial: 'bg-amber-100 text-amber-700',
 failed: 'bg-red-100 text-red-700',
 };
 return map[status] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300';
}

function fmtDate(iso: string | null): string {
 if (!iso) return '—';
 return new Date(iso).toLocaleString();
}

const expandedRunId = ref<number | null>(null);

function toggleErrors(id: number) {
 expandedRunId.value = expandedRunId.value === id ? null : id;
}

// Shopify connect form
const shopifyForm = useForm<{ shop_domain: string; access_token: string }>({
 shop_domain: '',
 access_token: '',
});

function connectShopify() {
 shopifyForm.post('/ingestion/shopify/connect', { preserveScroll: true });
}

function disconnectShopify() {
 router.delete('/ingestion/shopify/disconnect', { preserveScroll: true });
}

function triggerSync() {
 router.post('/ingestion/shopify/sync', {}, { preserveScroll: true });
}
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />

 <main class="max-w-5xl mx-auto px-6 py-10 space-y-10">

 <!-- Page title -->
 <div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Data Ingestion</h1>
 <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Data Sources & Import Runs</p>
 </div>

 <!-- Non-owner notice -->
 <div v-if="!canUpload" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
 You have read-only access. Only owners can upload files.
 </div>

 <!-- Shopify connector -->
 <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 shadow-sm space-y-4">
 <div class="flex items-center gap-3">
 <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24">
 <path d="M15.337 23.979l6.43-1.394c.277-.06.479-.31.479-.598V5.848a.607.607 0 0 0-.584-.607l-6.325-.407zm-1.066.021V4.84L8.63 3.8a.607.607 0 0 0-.663.601V22.6zm-7.704-1.671l-5.14-1.115A.607.607 0 0 1 1 20.62V6.007a.607.607 0 0 1 .67-.604l5.557.756z"/>
 </svg>
 <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Shopify Connector</h2>
 </div>

 <!-- Connected state -->
 <template v-if="shopify?.connected">
 <div class="flex items-center justify-between rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3">
 <div>
 <p class="text-sm font-medium text-emerald-800">Connected to {{ shopify.shop_domain }}</p>
 <p class="text-xs text-emerald-600 mt-0.5">
 Last sync: {{ fmtDate(shopify.last_sync_at) }}
 · Connected: {{ fmtDate(shopify.connected_at) }}
 </p>
 </div>
 <div v-if="canUpload" class="flex gap-2 shrink-0">
 <button
 @click="triggerSync"
 class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition-colors"
 >
 Sync Now
 </button>
 <button
 @click="disconnectShopify"
 class="rounded-lg bg-white dark:bg-slate-900 border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition-colors"
 >
 Disconnect
 </button>
 </div>
 </div>
 <p class="text-xs text-slate-400 dark:text-slate-500">
 SKU stock levels are synced hourly. Sales history is pulled from Shopify orders.
 Unmapped Shopify SKU codes appear as errors in the run log below.
 </p>
 </template>

 <!-- Disconnected / not connected state -->
 <template v-else>
 <p class="text-xs text-slate-500 dark:text-slate-400">
 Connect your Shopify store to automatically sync stock levels and sales history.
 Import suppliers and SKUs via CSV first — Shopify does not provide supplier data.
 </p>
 <form v-if="canUpload" @submit.prevent="connectShopify" class="space-y-3">
 <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Shop domain</label>
 <input
 v-model="shopifyForm.shop_domain"
 type="text"
 placeholder="your-store.myshopify.com"
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
 />
 <p v-if="shopifyForm.errors.shop_domain" class="mt-1 text-xs text-red-500">
 {{ shopifyForm.errors.shop_domain }}
 </p>
 </div>
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Admin API access token</label>
 <input
 v-model="shopifyForm.access_token"
 type="password"
 placeholder="shpat_..."
 class="w-full rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
 />
 <p v-if="shopifyForm.errors.access_token" class="mt-1 text-xs text-red-500">
 {{ shopifyForm.errors.access_token }}
 </p>
 </div>
 </div>
 <button
 type="submit"
 :disabled="shopifyForm.processing"
 class="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
 >
 {{ shopifyForm.processing ? 'Connecting…' : 'Connect Shopify' }}
 </button>
 </form>
 </template>
 </div>

 <!-- CSV upload sections -->
 <div class="space-y-6">
 <div
 v-for="section in sections"
 :key="section.entity"
 class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 shadow-sm"
 >
 <div class="flex items-start justify-between gap-4">
 <div>
 <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ section.label }}</h2>
 <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ section.description }}</p>
 </div>
 <a
 :href="`/ingestion/template/${section.entity}`"
 class="shrink-0 text-xs text-blue-600 hover:underline"
 download
 >
 Download template
 </a>
 </div>

 <form
 v-if="canUpload"
 class="mt-4 flex items-center gap-3"
 @submit.prevent="submit(section.entity)"
 >
 <input
 :id="`file-${section.entity}`"
 type="file"
 accept=".csv,text/csv"
 class="block text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 dark:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-slate-700 hover:file:bg-slate-200"
 @change="onFileChange(section.entity, $event)"
 />
 <button
 type="submit"
 :disabled="!forms[section.entity].file || forms[section.entity].processing"
 class="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
 >
 {{ forms[section.entity].processing ? 'Uploading…' : 'Upload' }}
 </button>
 <span
 v-if="forms[section.entity].errors.file"
 class="text-xs text-red-600"
 >
 {{ forms[section.entity].errors.file }}
 </span>
 </form>
 </div>
 </div>

 <!-- Recent runs -->
 <div>
 <h2 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Recent Import Runs</h2>

 <div v-if="runs.length === 0" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
 <EmptyState
 dense
 title="No import runs yet."
 description="Connect Shopify above or upload a CSV to see ingestion activity here."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
 </svg>
 </template>
 </EmptyState>
 </div>

 <div v-else class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
 <table class="w-full text-xs">
 <thead class="bg-slate-50 dark:bg-slate-800 text-left text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
 <tr>
 <th class="px-4 py-2.5 font-medium">Entity</th>
 <th class="px-4 py-2.5 font-medium">Source</th>
 <th class="px-4 py-2.5 font-medium">Status</th>
 <th class="px-4 py-2.5 font-medium text-right">Processed</th>
 <th class="px-4 py-2.5 font-medium text-right">OK</th>
 <th class="px-4 py-2.5 font-medium text-right">Failed</th>
 <th class="px-4 py-2.5 font-medium">Started</th>
 <th class="px-4 py-2.5 font-medium"></th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <template v-for="run in runs" :key="run.id">
 <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors">
 <td class="px-4 py-2.5 font-medium text-slate-700 dark:text-slate-200">
 {{ run.metadata?.entity ?? run.importer }}
 </td>
 <td class="px-4 py-2.5 text-slate-500 dark:text-slate-400 capitalize">
 {{ run.source === 'csv_upload' ? 'CSV' : run.source }}
 </td>
 <td class="px-4 py-2.5">
 <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusBadgeClass(run.status)]">
 {{ run.status }}
 </span>
 </td>
 <td class="px-4 py-2.5 text-right text-slate-600 dark:text-slate-300">{{ run.rows_processed }}</td>
 <td class="px-4 py-2.5 text-right text-green-700">{{ run.rows_succeeded }}</td>
 <td class="px-4 py-2.5 text-right" :class="run.rows_failed > 0 ? 'text-red-600' : 'text-slate-400 dark:text-slate-500'">
 {{ run.rows_failed }}
 </td>
 <td class="px-4 py-2.5 text-slate-500 dark:text-slate-400">{{ fmtDate(run.started_at) }}</td>
 <td class="px-4 py-2.5">
 <button
 v-if="run.error_log && run.error_log.length > 0"
 class="text-blue-600 hover:underline"
 @click="toggleErrors(run.id)"
 >
 {{ expandedRunId === run.id ? 'Hide' : 'Errors' }}
 </button>
 </td>
 </tr>
 <tr v-if="expandedRunId === run.id && run.error_log" class="bg-red-50">
 <td colspan="8" class="px-4 py-3">
 <p class="mb-1.5 text-xs font-semibold text-red-700">Row errors:</p>
 <ul class="space-y-0.5">
 <li
 v-for="(err, i) in run.error_log"
 :key="i"
 class="text-xs text-red-700"
 >
 <span v-if="err.row">Row {{ err.row }}: </span>
 <span v-if="err.errors">{{ err.errors.join(' · ') }}</span>
 <span v-else-if="err.error">{{ err.error }}</span>
 </li>
 </ul>
 </td>
 </tr>
 </template>
 </tbody>
 </table>
 </div>
 </div>

 </main>
 </div>
</template>
