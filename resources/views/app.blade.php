<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name') }}</title>

        {{-- Theme bootstrap — applied BEFORE Vite assets load so the user
             never sees a flash of the wrong theme. Reads the persisted
             preference from localStorage; falls back to OS preference. --}}
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('inventory-engine.theme');
                    var pref = (stored === 'light' || stored === 'dark' || stored === 'system') ? stored : 'system';
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var effective = pref === 'system' ? (prefersDark ? 'dark' : 'light') : pref;
                    if (effective === 'dark') {
                        document.documentElement.classList.add('dark');
                        document.documentElement.style.colorScheme = 'dark';
                    } else {
                        document.documentElement.style.colorScheme = 'light';
                    }
                } catch (e) { /* localStorage may be blocked; no-op */ }
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="antialiased bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100">
        @inertia
    </body>
</html>
