<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Pharmacy Suite') }}</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/img/favicon.png') }}?v=2">

    {{-- Set the theme before the first paint.

         React applies it too, but only once the bundle has downloaded and
         run — by which time the boot loader has already painted. Without
         this, anyone on the dark theme gets a white flash on every load.
         Deliberately inline and blocking; it is three lines. --}}
    <script>
        try {
            var stored = JSON.parse(localStorage.getItem('app.settings') || '{}');
            var theme = stored.theme || 'system';

            if (theme === 'system') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            document.documentElement.dataset.theme = theme;
        } catch (e) {
            document.documentElement.dataset.theme = 'light';
        }
    </script>

    {{-- Theme stylesheets. The matching jQuery plugins are deliberately gone;
         their behaviour lives in React components now.

         versionedAsset() rather than asset(): Vite fingerprints the bundle it
         builds, but nothing fingerprints these, so an edited file kept being
         served from cache and styled fresh markup with stale rules. --}}
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/style.min.css') }}">
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/custom.css') }}">
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/auth.css') }}">
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/table.css') }}">
    <link rel="stylesheet" href="{{ versionedAsset('vendor/css/layout.css') }}">

    @viteReactRefresh
    @vite('resources/js/app/main.tsx')
</head>

<body>
    {{-- Boot loader: part of the HTML so it paints immediately, before the JS
         bundle downloads. Same markup as FullPageLoaderHost, so the hand-over
         to the SPA's own overlay is invisible. React removes it once the
         session check finishes, after which loading is shown per section. --}}
    <div class="hospital-loader" id="app-loader" role="status">
        <span class="visually-hidden">Loading…</span>

        <div class="loader-card">
            <div class="loader-border"></div>

            <div class="loader-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>
            </div>
        </div>
    </div>

    <div id="root"></div>
</body>

</html>
