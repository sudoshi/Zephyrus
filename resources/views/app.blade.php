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
                document.documentElement.classList.add('dark', 'no-transitions');
                window.addEventListener('load', function() {
                    // Remove no-transitions after initial render
                    requestAnimationFrame(function() {
                        document.documentElement.classList.remove('no-transitions');
                    });
                });
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
