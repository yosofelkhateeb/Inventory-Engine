import { computed, ref } from 'vue';

export interface FilterDefinition {
    key: string;
    label: string;
    options: { value: string; label: string }[];
}

/**
 * Client-side search, filter, and sort for table data.
 *
 * @param source - A getter function returning the reactive data array.
 *   IMPORTANT: must return reactive data (e.g. `() => props.rows` or
 *   `() => someComputedRef.value`) so that `filtered` re-evaluates when
 *   data changes. Passing a plain non-reactive array will silently produce
 *   stale results.
 * @param searchFields - Fields to match the search string against.
 */
export function useTableControls<T extends Record<string, unknown>>(
    source: () => T[],
    searchFields: (keyof T)[],
) {
    const search = ref('');
    const activeFilters = ref<Record<string, string>>({});
    const sortKey = ref<keyof T | null>(null);
    const sortDir = ref<'asc' | 'desc'>('asc');

    function setSort(key: keyof T): void {
        if (sortKey.value === key) {
            sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortDir.value = 'asc';
        }
    }

    function setFilter(key: string, value: string): void {
        activeFilters.value = { ...activeFilters.value, [key]: value };
    }

    function clearAll(): void {
        search.value = '';
        activeFilters.value = {};
        sortKey.value = null;
        sortDir.value = 'asc';
    }

    const filtered = computed<T[]>(() => {
        let rows = source();

        // 1. Search
        const q = search.value.trim().toLowerCase();
        if (q) {
            rows = rows.filter(row =>
                searchFields.some(field => {
                    const val = row[field];
                    return val != null && String(val).toLowerCase().includes(q);
                }),
            );
        }

        // 2. Filters
        for (const [key, value] of Object.entries(activeFilters.value)) {
            if (!value || value === 'all') continue;
            rows = rows.filter(row => String((row as Record<string, unknown>)[key] ?? '') === value);
        }

        // 3. Sort
        if (sortKey.value !== null) {
            const key = sortKey.value;
            const dir = sortDir.value === 'asc' ? 1 : -1;
            rows = [...rows].sort((a, b) => {
                const av = a[key];
                const bv = b[key];
                if (av == null && bv == null) return 0;
                if (av == null) return 1;
                if (bv == null) return -1;
                if (typeof av === 'number' && typeof bv === 'number') {
                    return (av - bv) * dir;
                }
                return String(av).localeCompare(String(bv)) * dir;
            });
        }

        return rows;
    });

    const resultCount = computed(() => filtered.value.length);
    const totalCount = computed(() => source().length);

    return {
        search,
        activeFilters,
        sortKey,
        sortDir,
        setSort,
        setFilter,
        clearAll,
        filtered,
        resultCount,
        totalCount,
    };
}
