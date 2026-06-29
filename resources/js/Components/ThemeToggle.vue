<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { useTheme, type ThemePreference } from '@/composables/useTheme';

/**
 * Three-state theme toggle: System / Light / Dark.
 *
 * - 'System' follows the OS preference (and reacts live if the user flips
 * their OS theme mid-session).
 * - 'Light' / 'Dark' force the choice and persist to localStorage.
 *
 * Renders as a single icon button; clicking opens a small popover with
 * the three choices. The current selection is highlighted; the resulting
 * effective mode is reflected by the icon (sun for light, moon for dark).
 */
const { preference, effective, setPreference } = useTheme();
const open = ref(false);

const containerRef = ref<HTMLDivElement | null>(null);

function toggle(): void {
 open.value = !open.value;
}

function pick(pref: ThemePreference): void {
 setPreference(pref);
 open.value = false;
}

function onClickOutside(e: MouseEvent): void {
 if (!containerRef.value) return;
 if (!containerRef.value.contains(e.target as Node)) {
 open.value = false;
 }
}

onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));

const choices: { value: ThemePreference; label: string }[] = [
 { value: 'system', label: 'System' },
 { value: 'light', label: 'Light' },
 { value: 'dark', label: 'Dark' },
];
</script>

<template>
 <div ref="containerRef" class="relative">
 <button
 @click.stop="toggle"
 type="button"
 class="inline-flex items-center justify-center min-w-[44px] min-h-[44px] text-slate-500 hover:text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:bg-slate-800 dark:hover:text-slate-200 dark:hover:bg-slate-800 rounded-lg transition-colors duration-150 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
 :aria-label="`Theme: ${preference}. Click to change.`"
 :aria-expanded="open"
 aria-haspopup="true"
 >
 <!-- Sun icon (light effective) -->
 <svg v-if="effective === 'light'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <circle cx="12" cy="12" r="4" stroke-width="2"/>
 <path stroke-linecap="round" stroke-width="2" d="M12 2v2m0 16v2m10-10h-2M4 12H2m15.5-7.5l-1.4 1.4M7.9 16.1l-1.4 1.4m0-13l1.4 1.4m8.2 8.2l1.4 1.4"/>
 </svg>
 <!-- Moon icon (dark effective) -->
 <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
 </svg>
 </button>

 <div
 v-if="open"
 class="absolute right-0 top-full mt-2 w-36 bg-white dark:bg-slate-900 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 z-50 overflow-hidden"
 role="menu"
 >
 <button
 v-for="c in choices"
 :key="c.value"
 @click="pick(c.value)"
 type="button"
 class="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm text-left transition-colors duration-100 cursor-pointer"
 :class="preference === c.value
 ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-semibold'
 : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700'"
 role="menuitemradio"
 :aria-checked="preference === c.value"
 >
 <span>{{ c.label }}</span>
 <svg
 v-if="preference === c.value"
 class="w-3.5 h-3.5"
 fill="none"
 stroke="currentColor"
 stroke-width="2.5"
 stroke-linecap="round"
 stroke-linejoin="round"
 viewBox="0 0 24 24"
 aria-hidden="true"
 ><path d="M5 13l4 4L19 7" /></svg>
 </button>
 </div>
 </div>
</template>
