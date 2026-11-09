<?php

namespace App\Http\Middleware;

use App\Support\Edition;
use App\Support\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A self-hosted install that isn't set up yet answers only its installer
 * (/install): every page goes there, and anything else is turned away
 * until it's done. The health check and the HTTPS certificate check still
 * answer, so the web server can get a certificate for the installer's own
 * address. On the hosted edition, and once an install is set up, it does nothing.
 */
class SendToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Edition::selfHosted() || $request->is('up', 'api/internal/tls-check')) {
            return $next($request);
        }

        $installer = app(Installer::class);
        if ($installer->isSetUp()) {
            return $next($request);
        }

        try {
            $installer->prepare($request);
        } catch (Throwable $e) {
            return response($this->problem($e->getMessage()), 503, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        if ($request->is('install', 'install/*')) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect('/install');
        }

        return response()->json(['message' => 'ServerOS isn’t set up yet. Open /install in a browser to set it up.'], 503);
    }

    /** A page that needs nothing (no session, no app), for what stops the installer opening. */
    private function problem(string $message): string
    {
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Set up ServerOS</title>'
            .'<body style="font:15px/1.5 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 16px;color:#1a1a1a">'
            .'<h1 style="font-size:20px;font-weight:600">ServerOS can’t start its installer yet</h1>'
            .'<p>'.e($message).'</p></body>';
    }
}
