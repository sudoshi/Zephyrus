<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ request()->header('X-CSRF-TOKEN') }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Prevent flash of light mode -->
        <script>
            (function() {
                // Check localStorage first
                const savedTheme = localStorage.getItem('darkMode');
                // Use dark mode by default if no preference is stored
                // or if system prefers dark mode
                if (savedTheme === 'true' || savedTheme === null || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                    // Set localStorage to true if it's null
                    if (savedTheme === null) {
                        localStorage.setItem('darkMode', 'true');
                    }
                }
                document.documentElement.classList.add('no-transitions');
                // Add transition class after a short delay to ensure initial state is set
                setTimeout(function() {
                    document.documentElement.classList.remove('no-transitions');
                    document.documentElement.classList.add('transition-colors', 'duration-200');
                }, 0);
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @production
            @php
                $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
            @endphp
            <link rel="stylesheet" href="/build/{{ $manifest['resources/js/app.jsx']['css'][0] }}">
            <script type="module" src="/build/{{ $manifest['resources/js/app.jsx']['file'] }}"></script>
        @else
            @viteReactRefresh
            @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @endproduction
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
