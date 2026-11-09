<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        @include('partials.gpu-check')

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: #111111;
            }
        </style>
        @if ($page['component'] === 'status/Embed')
            {{-- In someone else's iframe. Browsers paint an opaque backdrop behind
                 a frame whose colour scheme differs from its page's, so this
                 doesn't rely on transparency: the page is the card's colour, and
                 the embed snippet rounds the iframe itself. --}}
            @php($embedTheme = $page['props']['theme'] ?? 'light')
            <style>
                html, html.dark, body { background: #ffffff !important; color-scheme: light; }
                @if ($embedTheme === 'dark')
                    html, html.dark, body { background: #161618 !important; color-scheme: dark; }
                @elseif ($embedTheme === 'auto')
                    @media (prefers-color-scheme: dark) { html, html.dark, body { background: #161618 !important; color-scheme: dark; } }
                @endif
            </style>
        @endif

        @if ($page['component'] === 'status/Show' && ! empty($page['props']['meta']))
            {{-- A public status page's link preview: its status in words, colour and picture. --}}
            @php($meta = $page['props']['meta'])
            <meta name="description" content="{{ $meta['description'] }}">
            <link rel="canonical" href="{{ url()->current() }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="ServerOS">
            <meta name="theme-color" content="{{ $meta['color'] }}">
            <meta property="og:url" content="{{ url()->current() }}">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta property="og:image" content="{{ $meta['image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $meta['image_alt'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $meta['title'] }}">
            <meta name="twitter:description" content="{{ $meta['description'] }}">
            <meta name="twitter:image" content="{{ $meta['image'] }}">
            @if (! empty($meta['discord']))
                {{-- Encoded with < > & escaped, so it can't close this element early. --}}
                <script id="discord:component-embed" type="application/json">{!! $meta['discord'] !!}</script>
            @endif
        @endif

        @if ($page['component'] === 'status/Show' && ! empty($page['props']['favicon']))
            {{-- A status page's own favicon. --}}
            <link rel="icon" href="{{ $page['props']['favicon'] }}">
            <link rel="apple-touch-icon" href="{{ $page['props']['favicon'] }}">
        @else
            @include('partials.favicons')
        @endif

        {{-- The panel's families; Inter Tight is the marketing site's only. --}}
        @fonts(['instrument-sans', 'mona-sans', 'geist-mono', 'cabin', 'inter', 'schibsted-grotesk'])

        {{-- Seline: cookieless analytics, in production with a project token
             (SELINE_TOKEN; serveros.com's package gives serveros.com's). --}}
        @if (app()->isProduction() && filled(config('services.seline.token')))
            @unless ($page['component'] === 'status/Embed')
                <script async src="/vendor/seline.js" data-token="{{ config('services.seline.token') }}"></script>
            @endunless
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.ts', \App\Support\Extensions\Extensions::pageEntry($page['component'], 'pages', 'vue')])
        {{-- The page's language (lib/i18n.ts loads only that one, and English
             needs none), fetched alongside the code rather than after it. --}}
        @php($panelLines = app()->getLocale() !== 'en' && ! Vite::isRunningHot() && is_file(lang_path(app()->getLocale().'.json')) ? rescue(fn () => Vite::asset('lang/'.app()->getLocale().'.json'), null, false) : null)
        @if ($panelLines)
            <link rel="modulepreload" href="{{ $panelLines }}">
        @endif
        {{-- Only public status pages belong in search. Sign in, sign up, invitations
             and everything behind them stay out, so crawlers that follow the site's
             "Sign in" link (or a panel address that sends them there) drop it. --}}
        @php($indexable = $page['component'] === 'status/Show')
        {{-- Sign in and sign up on the main site may be found too ("ServerOS
             login"), at their plain address, whatever was in the query. --}}
        @php($entry = in_array($page['component'], ['auth/Login', 'auth/Register'], true)
            && request()->getHost() === parse_url((string) config('app.url'), PHP_URL_HOST))
        @if ($entry)
            <link rel="canonical" href="{{ url()->current() }}">
        @elseif (! $indexable)
            <meta name="robots" content="noindex">
        @endif
        {{-- Sign in and sign up named in the HTML too (the app retitles every page
             once it runs), with a line for link previews. --}}
        @php($authTitle = ['auth/Login' => 'Sign in', 'auth/Register' => 'Create account', 'auth/ForgotPassword' => 'Forgot password', 'auth/ResetPassword' => 'Reset password'][$page['component']] ?? null)
        @if ($authTitle)
            <meta name="description" content="{{ $page['component'] === 'auth/Register' ? 'Create a ServerOS account: connect the servers you already own and manage everything on them from one panel.' : 'Sign in to ServerOS, the panel for the servers you already own.' }}">
        @endif
        <x-inertia::head>
            <title>{{ $indexable && ! empty($page['props']['meta']['title']) ? $page['props']['meta']['title'] : ($authTitle ? $authTitle.' - '.config('app.name') : config('app.name', 'Laravel')) }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        {{-- Cloudflare's email obfuscation rewrites every mailto: into a
             /cdn-cgi/l/email-protection link that answers 404 to crawlers (thousands a
             month) and changes the HTML under React and Vue. These markers keep it out. --}}
        <!--email_off-->
        {{-- A status page's name and state in the HTML itself, for crawlers that
             don't run JavaScript. Visually hidden; app.ts removes it once the
             real page is drawn. --}}
        @if ($indexable && ! empty($page['props']['meta']['title']))
            <header id="page-outline" class="sr-only">
                <h1>{{ $page['props']['meta']['title'] }}</h1>
                <p>{{ $page['props']['meta']['description'] ?? '' }}</p>
            </header>
        @endif
        <x-inertia::app />
        <!--/email_off-->
    </body>
</html>
