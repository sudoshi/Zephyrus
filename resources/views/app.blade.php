<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="shortcut icon" href="/favicon.ico">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#14b8f4">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="msapplication-TileImage" content="/mstile-150x150.png">
        <meta name="msapplication-TileColor" content="#2b5797">
        <meta name="theme-color" content="#ffffff">

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
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @production
            @php
                $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
            @endphp
            <link rel="stylesheet" href="/build/{{ $manifest['resources/js/app.tsx']['css'][0] }}">
            <script type="module" src="/build/{{ $manifest['resources/js/app.tsx']['file'] }}"></script>
        @else
            @viteReactRefresh
            @vite(['resources/js/app.tsx'])
        @endproduction
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
