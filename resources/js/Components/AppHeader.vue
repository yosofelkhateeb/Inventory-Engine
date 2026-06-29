<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import NotificationBell from './NotificationBell.vue';
import CommandPalette from './CommandPalette.vue';
import ThemeToggle from './ThemeToggle.vue';
import { glossary } from '@/composables/useGlossary';

const page = usePage();
const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null);

// Detect platform for the keyboard hint (Mac shows ⌘K, others Ctrl+K).
const isMac = ref(false);
onMounted(() => {
 isMac.value = typeof navigator !== 'undefined'
 && /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
});

const shortcutHint = computed(() => isMac.value ? '⌘K' : 'Ctrl+K');

function openPalette(): void {
 paletteRef.value?.open();
}

const navLinks = [
 { href: '/', label: 'Dashboard' },
 { href: '/decisions', label: 'Recommendations' },
 { href: '/skus', label: 'SKUs' },
 { href: '/promotions', label: 'Promotions' },
 { href: '/reports', label: 'Reports' },
 { href: '/ingestion', label: 'Ingestion' },
 { href: '/settings', label: 'Settings' },
];

function isActive(href: string): boolean {
 if (href === '/') return page.url === '/' || page.url === '';
 return page.url === href || page.url.startsWith(href + '/');
}

function navClass(href: string): string {
 // 44×44 minimum touch target — Apple HIG / Material guidance.
 // Visual padding stays compact via inline-flex + min-h to keep the
 // header at 56px while expanding the hit area to the full row height.
 const base = 'inline-flex items-center px-3 min-h-[44px] text-sm rounded-lg transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';
 return isActive(href)
 ? `${base} font-semibold text-blue-600 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/30`
 : `${base} text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 dark:hover:text-slate-200 hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-800`;
}

const glossaryOpen = ref(false);
const glossarySearch = ref('');

const glossaryEntries = computed(() => Object.values(glossary));

const filteredGlossary = computed(() => {
 const q = glossarySearch.value.trim().toLowerCase();
 if (!q) return glossaryEntries.value;
 return glossaryEntries.value.filter(e =>
 e.term.toLowerCase().includes(q) || e.definition.toLowerCase().includes(q)
 );
});
</script>

<template>
 <header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 sticky top-0 z-40">
 <div class="max-w-7xl mx-auto px-6 lg:px-8 min-h-[56px] flex items-center justify-between gap-4">
 <Link
 href="/"
 class="inline-flex items-center min-h-[44px] text-sm font-bold text-slate-800 dark:text-slate-100 tracking-tight hover:text-blue-600 dark:hover:text-blue-400 transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 rounded-md shrink-0"
 >
 Inventory Engine
 </Link>

 <!-- Search trigger — opens the Cmd+K palette. Visible click-discoverable
 entry point (mobile users have no keyboard, desktop users may not
 know the shortcut yet). -->
 <button
 @click="openPalette"
 class="hidden sm:inline-flex items-center gap-2 flex-1 max-w-xs px-3 min-h-[36px] text-xs text-slate-400 dark:text-slate-500 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 :aria-label="`Open command palette (${shortcutHint})`"
 >
 <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
 </svg>
 <span class="flex-1 text-left">Search</span>
 <kbd class="hidden md:inline-flex items-center px-1.5 py-0 rounded bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-[10px] text-slate-500 dark:text-slate-400">{{ shortcutHint }}</kbd>
 </button>

 <!-- Mobile: search icon only (no keyboard, but still reachable) -->
 <button
 @click="openPalette"
 class="sm:hidden inline-flex items-center justify-center min-w-[44px] min-h-[44px] text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-800 rounded-lg transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 aria-label="Open command palette"
 >
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
 </svg>
 </button>

 <nav aria-label="Main navigation" class="flex items-center gap-1 shrink-0">
 <Link
 v-for="link in navLinks"
 :key="link.href"
 :href="link.href"
 :class="navClass(link.href)"
 :aria-current="isActive(link.href) ? 'page' : undefined"
 >
 {{ link.label }}
 </Link>
 <button
 @click="glossaryOpen = true"
 class="ml-1 inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-full text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-800 transition-colors duration-150 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 aria-label="Open glossary"
 title="Glossary"
 >?</button>
 <ThemeToggle />
 <NotificationBell />
 </nav>
 </div>
 </header>

 <!-- Cmd+K command palette — mounted globally so it's available from
 any authenticated page. Opens via Cmd/Ctrl+K (document keydown),
 click on the search chip, or programmatically via paletteRef. -->
 <CommandPalette ref="paletteRef" />

 <Teleport to="body">
 <div v-if="glossaryOpen" class="fixed inset-0 z-50 flex justify-end">
 <!-- Backdrop -->
 <div class="absolute inset-0 bg-black/30" @click="glossaryOpen = false" />

 <!-- Panel -->
 <div class="relative z-10 w-80 h-full bg-white dark:bg-slate-900 shadow-2xl border-l border-slate-200 dark:border-slate-700 flex flex-col">
 <!-- Header -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
 <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100">Glossary</h2>
 <button
 @click="glossaryOpen = false"
 class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 dark:hover:text-slate-200 transition-colors"
 aria-label="Close glossary"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>

 <!-- Search -->
 <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 shrink-0">
 <input
 v-model="glossarySearch"
 type="text"
 placeholder="Search terms…"
 class="w-full px-3 py-2 text-sm bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
 />
 </div>

 <!-- Terms list -->
 <div class="flex-1 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
 <div
 v-for="entry in filteredGlossary"
 :key="entry.term"
 class="px-5 py-3"
 >
 <p class="text-xs font-semibold text-slate-700 dark:text-slate-200 mb-0.5">{{ entry.term }}</p>
 <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">{{ entry.definition }}</p>
 </div>
 <div v-if="filteredGlossary.length === 0" class="px-5 py-8 text-center text-xs text-slate-400 dark:text-slate-500">
 No terms match your search.
 </div>
 </div>
 </div>
 </div>
 </Teleport>
</template>
