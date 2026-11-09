<?php

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use App\Support\Install\SetupCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer's routes: gone (404) once the install is set up, and with
 * `:code`, only for a session that entered the setup code, checked again
 * on every request (a new code signs it out).
 */
class EnsureInstalling
{
    public const SESSION_KEY = 'install.code';

    public function __construct(private Installer $installer, private SetupCode $code) {}

    public function handle(Request $request, Closure $next, ?string $needs = null): Response
    {
        abort_unless($this->installer->needed(), 404);

        if ($needs === 'code' && ! self::entered($request, $this->code)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Enter the setup code first.'], 403)
                : redirect('/install')->withErrors(['code' => 'Enter the setup code first.']);
        }

        return $next($request);
    }

    public static function entered(Request $request, SetupCode $code): bool
    {
        $fingerprint = $code->fingerprint();

        return $fingerprint !== null && hash_equals($fingerprint, (string) $request->session()->get(self::SESSION_KEY));
    }
}
