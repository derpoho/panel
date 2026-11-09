@use('Laravel\Pulse\Facades\Pulse')
@props(['cols' => 12, 'fullWidth' => false])
{{--
    Laravel Pulse inside the admin: the panel's fonts, colours and light or
    dark setting (not Pulse's own theme switcher), a way back to the admin,
    and resources/css/pulse.css after Pulse's CSS to recolour its grays.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Pulse · ServerOS admin</title>

        <script>
            (function () {
                if ('{{ $appearance ?? 'system' }}' === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        @include('partials.favicons')

        @fonts
        @vite(['resources/css/app.css'])

        {!! Pulse::css() !!}
        <style>{!! file_get_contents(resource_path('css/pulse.css')) !!}</style>
        @livewireStyles

        {!! Pulse::js() !!}
        @livewireScriptConfig
    </head>
    <body class="pulse-panel">
        <div class="min-h-screen">
            <header class="px-5">
                <div class="{{ $fullWidth ? '' : 'container' }} mx-auto border-b border-gray-200 py-3 sm:py-4 dark:border-gray-900">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <a href="/admin" class="flex items-center gap-2" style="color: var(--ds-text-muted)" title="Back to the admin">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                                <span style="font-size: 13px">Admin</span>
                            </a>
                            <span style="width: 1px; height: 18px; background: var(--ds-border)" aria-hidden="true"></span>
                            <img src="/images/landing/serveros-logo.svg" alt="" class="pulse-logo" style="height: 18px; width: auto">
                            <span style="font-size: 17px; font-weight: 550; color: var(--ds-text)">Pulse</span>
                        </div>
                        {{-- Pulse's period picker as the panel's segmented control. --}}
                        @php($period = request()->query('period', '1_hour'))
                        <nav class="ds-segments" aria-label="Period">
                            @foreach (['1_hour' => '1h', '6_hours' => '6h', '24_hours' => '24h', '7_days' => '7d'] as $value => $label)
                                <a
                                    href="?{{ http_build_query([...request()->except('period'), ...($value === '1_hour' ? [] : ['period' => $value])]) }}"
                                    @if ($period === $value) aria-current="page" @endif
                                    style="padding: 4px 10px; font-size: 13px; font-weight: 500"
                                >{{ $label }}</a>
                            @endforeach
                        </nav>
                    </div>
                </div>
            </header>

            <main class="px-6 pt-6 pb-12">
                <div {{ $attributes->merge(['class' => "mx-auto grid default:grid-cols-{$cols} default:gap-6" . ($fullWidth ? '' : ' container')]) }}>
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
