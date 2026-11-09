<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The daemon installer at /install.sh, with this panel's own addresses as
 * its defaults: where the daemon enrols (daemon.panel_url) and where it
 * downloads releases from (daemon.releases_url). A web server that serves
 * a public/install.sh itself never gets here; a self-hosted install's image
 * has none, so its installer always points at itself. A package can serve
 * its own script instead (daemon.install_script).
 */
class DaemonInstallScriptController extends Controller
{
    public function __invoke(): Response
    {
        $script = (string) file_get_contents((string) (config('daemon.install_script') ?: resource_path('daemon/install.sh')));

        return response(strtr($script, [
            '__SERVEROS_INSTALL_URL__' => self::safe(config('daemon.install_url')),
            '__SERVEROS_PANEL_URL__' => self::safe(config('daemon.panel_url')),
            '__SERVEROS_RELEASES_URL__' => self::safe(config('daemon.releases_url')),
        ]), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * An address to put inside the script's double quotes: a plain http(s)
     * URL, or nothing (the script then asks for --panel).
     */
    private static function safe(mixed $url): string
    {
        return is_string($url) && preg_match('#^https?://[A-Za-z0-9.\-:\[\]/_~%]+$#', $url) === 1 ? rtrim($url, '/') : '';
    }
}
