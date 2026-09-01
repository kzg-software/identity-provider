<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $systemName ?? config('app.name', 'Auth') }}</title>
    @if (! empty($systemFaviconUrl))
        <link rel="icon" href="{{ $systemFaviconUrl }}">
    @endif

    {{-- Theme-Auswahl (System / Hell / Dunkel) frueh anwenden, um Flackern zu vermeiden --}}
    <script>
        (function () {
            window.__themeKey = 'theme';
            window.__applyTheme = function (mode) {
                var m = mode || localStorage.getItem(window.__themeKey) || 'system';
                var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                var dark = m === 'dark' || (m === 'system' && systemDark);
                document.documentElement.classList.toggle('dark', dark);
            };
            window.__setTheme = function (mode) {
                if (mode === 'system') {
                    localStorage.removeItem(window.__themeKey);
                } else {
                    localStorage.setItem(window.__themeKey, mode);
                }
                window.__applyTheme(mode);
            };
            window.__currentTheme = function () {
                return localStorage.getItem(window.__themeKey) || 'system';
            };
            window.__applyTheme();
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                if (window.__currentTheme() === 'system') window.__applyTheme('system');
            });
        })();
    </script>

    <script src="{{ asset('vendor/tailwindcss/tailwindcss-3.4.16.js') }}"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        laravel: {
                            DEFAULT: '{{ $accentPalette['DEFAULT'] }}',
                            50: '{{ $accentPalette['50'] }}',
                            100: '{{ $accentPalette['100'] }}',
                            300: '{{ $accentPalette['300'] }}',
                            500: '{{ $accentPalette['500'] }}',
                            600: '{{ $accentPalette['600'] }}',
                            700: '{{ $accentPalette['700'] }}',
                        },
                    },
                    fontFamily: {
                        sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                },
            },
        }
    </script>
    <link href="{{ asset('vendor/fonts/figtree.css') }}" rel="stylesheet">
    <script src="{{ asset('vendor/alpinejs/alpinejs-3.14.1.min.js') }}" defer></script>
    <style>
        [x-cloak] { display: none !important; }

        /*
         * Dunkler Modus – gedaempfte, augenschonende Palette (kein reines Schwarz).
         * Die App nutzt durchgaengig Tailwind-Grau/Weiss-Utilities; hier werden diese
         * unter .dark zentral umgefaerbt, damit alle Views ohne Einzelanpassung passen.
         */
        /*
         * Steuert das Rendering nativer Steuerelemente (Inputs, Checkboxen,
         * Selects, Scrollbars). MUSS explizit gesetzt werden, sonst folgen
         * die Formularfelder im Hell-Modus weiter dem dunklen Betriebssystem-
         * Schema und wirken schwarz.
         */
        html { color-scheme: light; }
        html.dark { color-scheme: dark; }
        html.dark body { background-color: #171a21; color: #e7e9ee; }

        html.dark .bg-white { background-color: #1e222b; }
        html.dark .bg-gray-50 { background-color: #242934; }
        html.dark .bg-gray-100 { background-color: #171a21; }
        html.dark .bg-gray-200 { background-color: #2f3540; }
        html.dark .bg-gray-900 { background-color: #0d0f13; }
        html.dark .hover\:bg-gray-50:hover { background-color: #242934; }
        html.dark .hover\:bg-gray-100:hover { background-color: #2f3540; }

        html.dark .text-gray-900 { color: #e7e9ee; }
        html.dark .text-gray-800 { color: #dee1e8; }
        html.dark .text-gray-700 { color: #cfd4dd; }
        html.dark .text-gray-600 { color: #b8bfca; }
        html.dark .text-gray-500 { color: #98a0ac; }
        html.dark .text-gray-400 { color: #7c8593; }
        html.dark .hover\:text-gray-700:hover { color: #cfd4dd; }
        html.dark .hover\:text-gray-900:hover { color: #e7e9ee; }

        html.dark .border-gray-100 { border-color: #2a2f39; }
        html.dark .border-gray-200 { border-color: #333a45; }
        html.dark .border-gray-300 { border-color: #3d4550; }
        html.dark .divide-gray-100 > :not([hidden]) ~ :not([hidden]) { border-color: #2a2f39; }
        html.dark .divide-gray-200 > :not([hidden]) ~ :not([hidden]) { border-color: #333a45; }

        /* Formularfelder */
        html.dark input:not([type=checkbox]):not([type=radio]):not([type=color]),
        html.dark select, html.dark textarea {
            background-color: #1a1e26;
            color: #e7e9ee;
        }
        html.dark input::placeholder, html.dark textarea::placeholder { color: #6b7280; }

        /* Akzentflaechen etwas entsaettigen, damit sie nicht leuchten */
        html.dark .bg-laravel-50 { background-color: rgba({{ $accentPalette['rgb'] }}, 0.13); }
        html.dark .bg-laravel-100 { background-color: rgba({{ $accentPalette['rgb'] }}, 0.20); }
        html.dark .bg-red-50 { background-color: rgba(239, 68, 68, 0.12); }
        html.dark .bg-red-100 { background-color: rgba(239, 68, 68, 0.18); }
        html.dark .hover\:bg-red-100:hover { background-color: rgba(239, 68, 68, 0.2); }
        html.dark .bg-amber-50 { background-color: rgba(245, 158, 11, 0.12); }
        html.dark .bg-amber-100 { background-color: rgba(245, 158, 11, 0.18); }
        html.dark .hover\:bg-amber-100:hover { background-color: rgba(245, 158, 11, 0.2); }
        html.dark .bg-emerald-50 { background-color: rgba(16, 185, 129, 0.12); }
        html.dark .bg-emerald-100 { background-color: rgba(16, 185, 129, 0.18); }
        html.dark .bg-blue-50 { background-color: rgba(59, 130, 246, 0.12); }
        html.dark .bg-blue-100 { background-color: rgba(59, 130, 246, 0.18); }

        html.dark .text-laravel-600, html.dark .text-laravel-700 { color: {{ $accentPalette['300'] }}; }
        html.dark .hover\:text-laravel-700:hover { color: {{ $accentPalette['100'] }}; }
        html.dark .text-red-500 { color: #f87171; }
        html.dark .text-red-600, html.dark .text-red-700, html.dark .text-red-800 { color: #fca5a5; }
        html.dark .text-amber-500 { color: #fbbf24; }
        html.dark .text-amber-600, html.dark .text-amber-700, html.dark .text-amber-800 { color: #fcd34d; }
        html.dark .text-emerald-700, html.dark .text-emerald-800 { color: #6ee7b7; }
        html.dark .text-blue-700, html.dark .text-blue-800 { color: #93c5fd; }

        html.dark .border-red-200, html.dark .border-red-300 { border-color: rgba(239, 68, 68, 0.35); }
        html.dark .border-amber-200, html.dark .border-amber-300 { border-color: rgba(245, 158, 11, 0.35); }
        html.dark .border-emerald-200 { border-color: rgba(16, 185, 129, 0.35); }
        html.dark .border-blue-200 { border-color: rgba(59, 130, 246, 0.35); }
    </style>
    @stack('styles')
</head>
<body class="font-sans antialiased bg-gray-100 text-gray-900">
    @yield('content')

    @stack('scripts')
</body>
</html>
