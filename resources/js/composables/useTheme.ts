import { onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * Theme preference: 'system' follows OS dark/light, 'light'/'dark' force.
 *
 * Persisted to localStorage so the choice survives reloads. Hydration is
 * handled in two phases:
 *   1. A tiny inline script in resources/views/app.blade.php applies the
 *      correct class to <html> BEFORE Vue mounts, so there's no FOUC flash
 *      on page load.
 *   2. This composable picks up that initial value on mount and exposes
 *      reactive controls for the toggle button.
 *
 * The 'system' mode also listens to `prefers-color-scheme` so a user who
 * flips their OS theme mid-session sees the app follow without reload.
 */
export type ThemePreference = 'system' | 'light' | 'dark';

const STORAGE_KEY = 'inventory-engine.theme';

function getStoredPreference(): ThemePreference {
    if (typeof window === 'undefined') return 'system';
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return stored === 'light' || stored === 'dark' || stored === 'system'
        ? stored
        : 'system';
}

function systemPrefersDark(): boolean {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function resolveEffective(pref: ThemePreference): 'light' | 'dark' {
    if (pref === 'system') return systemPrefersDark() ? 'dark' : 'light';
    return pref;
}

function applyToDocument(effective: 'light' | 'dark'): void {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    if (effective === 'dark') {
        root.classList.add('dark');
        root.style.colorScheme = 'dark';
    } else {
        root.classList.remove('dark');
        root.style.colorScheme = 'light';
    }
}

export function useTheme() {
    const preference = ref<ThemePreference>(getStoredPreference());
    const effective  = ref<'light' | 'dark'>(resolveEffective(preference.value));

    function setPreference(pref: ThemePreference): void {
        preference.value = pref;
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, pref);
        }
    }

    // Re-resolve effective whenever preference changes, OR (for 'system')
    // when the OS preference changes.
    function recompute(): void {
        effective.value = resolveEffective(preference.value);
        applyToDocument(effective.value);
    }

    let mql: MediaQueryList | null = null;
    let mqlListener: ((e: MediaQueryListEvent) => void) | null = null;

    onMounted(() => {
        recompute();
        if (typeof window === 'undefined') return;
        mql = window.matchMedia('(prefers-color-scheme: dark)');
        mqlListener = () => {
            if (preference.value === 'system') recompute();
        };
        mql.addEventListener('change', mqlListener);
    });

    onUnmounted(() => {
        if (mql && mqlListener) mql.removeEventListener('change', mqlListener);
    });

    watch(preference, recompute);

    return {
        preference,
        effective,
        setPreference,
    };
}
