<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Cmd+K / Ctrl+K command palette.
 *
 * Opens from any authenticated page; offers instant fuzzy search across:
 * - Pages (Dashboard, Decisions, SKUs, Promotions, Reports, Ingestion, Settings)
 * - SKUs (by sku_code or name) — sourced from the `commandPaletteSkus`
 * shared Inertia prop populated by HandleInertiaRequests.
 * - Actions (Run Engine, etc) — `action` items execute a callback.
 *
 * Mounted once at the app header so it's available globally without
 * per-page wiring. Keyboard nav: ↑/↓ to move, ⏎ to select, Esc to close.
 */

interface SkuIndex {
 id: number;
 sku_code: string;
 name: string;
}

interface PaletteItem {
 id: string;
 label: string;
 sublabel?: string;
 /** 'page' navigates via Inertia; 'sku' opens the SKU detail; 'action' fires callback. */
 kind: 'page' | 'sku' | 'action';
 href?: string;
 action?: () => void;
 /** Search corpus — what gets matched against the query. */
 search: string;
}

const page = usePage();
const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const inputRef = ref<HTMLInputElement | null>(null);

// Shared SKU index (id / sku_code / name) injected via Inertia middleware.
const skuIndex = computed<SkuIndex[]>(
 () => (page.props.commandPaletteSkus as SkuIndex[] | undefined) ?? []
);

const pageItems: PaletteItem[] = [
 { id: 'page-dashboard', label: 'Dashboard', sublabel: 'Stock replenishment decisions', kind: 'page', href: '/', search: 'dashboard home overview' },
 { id: 'page-decisions', label: 'Recommendations', sublabel: 'Recommendation queue + audit', kind: 'page', href: '/decisions', search: 'decisions recommendations queue audit' },
 { id: 'page-skus', label: 'SKU Catalogue', sublabel: 'Browse all SKUs', kind: 'page', href: '/skus', search: 'skus catalogue inventory products' },
 { id: 'page-promotions', label: 'Promotional Calendar',sublabel: 'Plan demand uplifts', kind: 'page', href: '/promotions', search: 'promotions sales campaigns uplift' },
 { id: 'page-reports', label: 'Forecast Reports', sublabel: 'Per-SKU model accuracy', kind: 'page', href: '/reports', search: 'reports forecast accuracy smape mae' },
 { id: 'page-ingestion', label: 'Data Ingestion', sublabel: 'CSV upload and Shopify', kind: 'page', href: '/ingestion', search: 'ingestion upload csv shopify connector' },
 { id: 'page-settings', label: 'Forecast Settings', sublabel: 'Thresholds and calibration', kind: 'page', href: '/settings', search: 'settings thresholds calibration coefficients' },
];

function runEngine(): void {
 router.post('/engine/run');
 close();
}

const actionItems: PaletteItem[] = [
 { id: 'action-run-engine', label: 'Run Engine', sublabel: 'Trigger an inventory engine run', kind: 'action', action: runEngine, search: 'run engine trigger refresh recompute' },
];

const skuItems = computed<PaletteItem[]>(() =>
 skuIndex.value.map(sku => ({
 id: `sku-${sku.id}`,
 label: sku.name,
 sublabel: sku.sku_code,
 kind: 'sku',
 href: `/skus/${sku.id}`,
 search: `${sku.sku_code} ${sku.name}`.toLowerCase(),
 }))
);

const allItems = computed<PaletteItem[]>(() => [
 ...pageItems,
 ...actionItems,
 ...skuItems.value,
]);

const filtered = computed<PaletteItem[]>(() => {
 const q = query.value.trim().toLowerCase();
 if (!q) {
 // Empty query: show pages + actions, hide the SKU long list to avoid noise.
 return [...pageItems, ...actionItems];
 }
 return allItems.value.filter(item => item.search.toLowerCase().includes(q)).slice(0, 30);
});

watch(filtered, () => {
 activeIndex.value = 0;
});

function openPalette(): void {
 open.value = true;
 query.value = '';
 activeIndex.value = 0;
 nextTick(() => inputRef.value?.focus());
}

function close(): void {
 open.value = false;
 query.value = '';
}

function select(item?: PaletteItem): void {
 const target = item ?? filtered.value[activeIndex.value];
 if (!target) return;

 if (target.kind === 'action' && target.action) {
 target.action();
 return;
 }

 if (target.href) {
 router.visit(target.href);
 close();
 }
}

function onKeydown(e: KeyboardEvent): void {
 const isMod = e.metaKey || e.ctrlKey;
 if (isMod && e.key.toLowerCase() === 'k') {
 e.preventDefault();
 if (open.value) close();
 else openPalette();
 return;
 }

 if (!open.value) return;

 if (e.key === 'Escape') {
 e.preventDefault();
 close();
 } else if (e.key === 'ArrowDown') {
 e.preventDefault();
 activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1);
 } else if (e.key === 'ArrowUp') {
 e.preventDefault();
 activeIndex.value = Math.max(activeIndex.value - 1, 0);
 } else if (e.key === 'Enter') {
 e.preventDefault();
 select();
 }
}

onMounted(() => {
 document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
 document.removeEventListener('keydown', onKeydown);
});

function kindBadge(kind: PaletteItem['kind']): { label: string; classes: string } {
 switch (kind) {
 case 'page': return { label: 'Page', classes: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' };
 case 'sku': return { label: 'SKU', classes: 'bg-blue-100 text-blue-700' };
 case 'action': return { label: 'Action', classes: 'bg-emerald-100 text-emerald-700' };
 }
}

// Public API for parent components (header) to trigger the palette without
// dispatching a synthetic keyboard event. Click-discoverable trigger lives
// in AppHeader for users who don't know the keyboard shortcut.
defineExpose({ open: openPalette, close });
</script>

<template>
 <Teleport to="body">
 <div
 v-if="open"
 class="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4"
 @click.self="close"
 role="dialog"
 aria-modal="true"
 aria-label="Command palette"
 >
 <div class="absolute inset-0 bg-black/40" @click="close" />

 <div class="relative z-10 w-full max-w-xl bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden">
 <!-- Search input -->
 <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-100 dark:border-slate-800">
 <svg class="w-5 h-5 text-slate-400 dark:text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
 </svg>
 <input
 ref="inputRef"
 v-model="query"
 type="text"
 placeholder="Jump to a page, SKU, or action…"
 class="flex-1 bg-transparent text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none"
 autocomplete="off"
 spellcheck="false"
 aria-label="Search command palette"
 />
 <kbd class="hidden sm:inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-[10px] font-mono text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">
 Esc
 </kbd>
 </div>

 <!-- Results -->
 <div
 v-if="filtered.length > 0"
 class="max-h-80 overflow-y-auto py-1"
 role="listbox"
 >
 <button
 v-for="(item, idx) in filtered"
 :key="item.id"
 type="button"
 class="w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors duration-100 cursor-pointer"
 :class="idx === activeIndex
 ? 'bg-blue-50'
 : 'hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800'"
 :aria-selected="idx === activeIndex"
 role="option"
 @mouseenter="activeIndex = idx"
 @click="select(item)"
 >
 <span
 class="text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded shrink-0"
 :class="kindBadge(item.kind).classes"
 >
 {{ kindBadge(item.kind).label }}
 </span>
 <span class="flex-1 min-w-0">
 <span class="block text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ item.label }}</span>
 <span v-if="item.sublabel" class="block text-xs text-slate-400 dark:text-slate-500 font-mono truncate mt-0.5">{{ item.sublabel }}</span>
 </span>
 <span
 v-if="idx === activeIndex"
 class="text-[10px] font-mono text-slate-400 dark:text-slate-500 shrink-0"
 >
 ↵
 </span>
 </button>
 </div>

 <!-- Empty state -->
 <div v-else class="px-4 py-10 text-center text-sm text-slate-400 dark:text-slate-500">
 No matches for <span class="font-mono text-slate-600 dark:text-slate-300">{{ query }}</span>
 </div>

 <!-- Footer hint -->
 <div class="px-4 py-2 border-t border-slate-100 dark:border-slate-800 flex items-center gap-3 text-[11px] text-slate-400 dark:text-slate-500 bg-slate-50 dark:bg-slate-800/50">
 <span class="inline-flex items-center gap-1">
 <kbd class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-[10px]">↑</kbd>
 <kbd class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-[10px]">↓</kbd>
 navigate
 </span>
 <span class="inline-flex items-center gap-1">
 <kbd class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-[10px]">↵</kbd>
 select
 </span>
 <span class="ml-auto inline-flex items-center gap-1">
 <kbd class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-[10px]">⌘K</kbd>
 toggle
 </span>
 </div>
 </div>
 </div>
 </Teleport>
</template>
