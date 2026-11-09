<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · {{ $pageTitle }}</title>
    @include('partials.favicons')
    <style>
        :root { --bg: #fff; --ink: #151515; --muted: #6b6b6b; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b0b0d; --ink: #eeeeec; --muted: #9a9a9a; }
        }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; box-sizing: border-box; background: var(--bg); color: var(--ink); font: 14px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; }
        main { width: 100%; max-width: 360px; }
        h1 { margin: 0 0 4px; font-size: 14px; font-weight: 600; }
        p { margin: 0; color: var(--muted); overflow-wrap: anywhere; }
        .next { margin-top: 12px; }
        a { color: inherit; font-weight: 500; }
        /* The dashboard's primary button. */
        button { margin-top: 16px; height: 32px; padding: 0 12px; border: 1px solid rgb(59 123 255 / 0.6); border-radius: 6px; background: #1C4BB0; color: #fff; font: inherit; font-size: 13px; line-height: 16px; font-weight: 500; cursor: pointer; transition: background-color 150ms, border-color 150ms, scale 150ms; }
        button:hover { border-color: #3B7BFF; background: #2257C7; }
        button:active { scale: 0.97; }
        button:focus-visible { outline: 2px solid #3B7BFF; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ $body }}</p>
        @if ($action)
            <form method="post" action="{{ $action }}">
                <button type="submit">Unsubscribe</button>
            </form>
        @else
            <p class="next"><a href="{{ $pageUrl }}">Back to {{ $pageTitle }}</a></p>
        @endif
    </main>
</body>
</html>
