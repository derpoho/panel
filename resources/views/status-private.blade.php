<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <title>This status page is private</title>
    @include('partials.favicons')
    {{-- A private status page on its own domain: say so here, on that
         domain, rather than sending visitors off to a sign-in page they
         didn't expect. Members sign in on ours and come back to it. --}}
    <style>
        :root { --bg: #fff; --ink: #151515; --muted: #6b6b6b; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b0b0d; --ink: #eeeeec; --muted: #9a9a9a; }
        }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; box-sizing: border-box; background: var(--bg); color: var(--ink); font: 14px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; }
        main { width: 100%; max-width: 380px; }
        h1 { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
        p { margin: 0; color: var(--muted); overflow-wrap: anywhere; }
        a.button { display: inline-flex; align-items: center; margin-top: 16px; height: 32px; padding: 0 12px; border: 1px solid #4a7fe8; border-radius: 6px; background: #1f5fe0; color: #fff; font-size: 13px; font-weight: 500; text-decoration: none; }
        a.button:hover { background: #2f6ff0; }
        a.button:focus-visible { outline: 2px solid #4a7fe8; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <h1>This status page is private</h1>
        <p>Only members of {{ $workspace }} can see it. If you run it, you can make it public in ServerOS, under Status page.</p>
        <a class="button" href="{{ $signIn }}">Sign in to view</a>
    </main>
</body>
</html>
