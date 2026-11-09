<?php

namespace App\Support\Install;

use App\Models\User;
use App\Support\Edition;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The web installer of a self-hosted install: until it's set up, every
 * request goes to /install (SendToInstaller), which asks for the setup
 * code (SetupCode) and walks through the server, the database, the
 * panel's address, email and the owner's account. Finishing records the
 * install as set up in the database (install_identity.set_up_at), and
 * from then on the installer is gone. serveros.com never has one.
 *
 * An install set up before the installer existed already has accounts,
 * and counts as set up.
 */
#[Singleton]
final class Installer
{
    public const STEPS = ['code', 'check', 'database', 'address', 'mail', 'owner', 'done'];

    /** The session cookie while installing, apart from the panel's own. */
    public const SESSION_COOKIE = 'serveros_install';

    private const CACHE_KEY = 'install:set-up';

    private bool $setUp = false;

    /** @var array<string, mixed>|null */
    private ?array $panelSession = null;

    /** @var array{default: mixed, limiter: mixed}|null */
    private ?array $panelCache = null;

    public function __construct(private SetupCode $code, private EnvFile $env) {}

    /** Whether requests go to the installer: self-hosted, and not set up yet. */
    public function needed(): bool
    {
        return Edition::selfHosted() && ! $this->isSetUp();
    }

    /** Running in the Docker image, where compose.yaml sets the database and address. */
    public static function docker(): bool
    {
        return (bool) config('serveros.install.docker');
    }

    public function isSetUp(): bool
    {
        if ($this->setUp) {
            return true;
        }

        try {
            if (Cache::get(self::CACHE_KEY) === true) {
                return $this->setUp = true;
            }
        } catch (Throwable) {
            // No cache yet (the database store before there's a database).
        }

        try {
            $setUp = DB::table('install_identity')->where('id', 1)->value('set_up_at') !== null;
            // Set up before the installer existed: it has accounts already.
            if (! $setUp && User::query()->exists()) {
                $this->markSetUp();

                return true;
            }
        } catch (Throwable) {
            // No database, or no tables yet. Once set up, a database that's
            // down for a moment doesn't bring the installer back.
            return $this->setUp = is_file($this->markerPath());
        }

        if ($setUp) {
            $this->remember();
        }

        return $this->setUp = $setUp;
    }

    /** Finishing: set up for good, and the setup code is gone. */
    public function markSetUp(): void
    {
        $this->record();
        $this->code->forget();
        $this->remember();
        $this->setUp = true;
    }

    /**
     * Ready an installer request, before the session starts: an APP_KEY if
     * there's none, and a session and cache that don't need the database
     * (there may not be one yet), on a cookie of its own that stays on the
     * address it was opened on.
     */
    public function prepare(Request $request): void
    {
        $this->ensureAppKey();

        $this->panelSession ??= (array) config('session');
        config([
            'session.driver' => config('session.driver') === 'database' ? 'file' : config('session.driver'),
            'session.cookie' => self::SESSION_COOKIE,
            'session.domain' => null,
            'session.secure' => $request->isSecure(),
        ]);

        $this->panelCache ??= ['default' => config('cache.default'), 'limiter' => config('cache.limiter')];
        if (config('cache.default') === 'database') {
            config(['cache.default' => 'file']);
        }
        if (config('cache.limiter') === 'database') {
            config(['cache.limiter' => 'file']);
        }
    }

    /**
     * Finishing: back to the panel's own cache, and the session the owner
     * is signed in with moves to the panel's own store and cookie, so they
     * arrive at the dashboard signed in.
     */
    public function handOver(Request $request): void
    {
        if ($this->panelCache !== null) {
            config(['cache.default' => $this->panelCache['default'], 'cache.limiter' => $this->panelCache['limiter']]);
        }

        $panel = $this->panelSession;
        if ($panel === null) {
            return;
        }

        $session = $request->session();
        if (($panel['driver'] ?? null) !== config('session.driver')) {
            $session->getHandler()->destroy($session->getId());
            $session->setHandler(app('session')->driver($panel['driver'])->getHandler());
        }
        $session->setName((string) $panel['cookie']);

        config([
            'session.cookie' => $panel['cookie'],
            'session.domain' => $panel['domain'],
            'session.secure' => $panel['secure'],
        ]);
    }

    /** A new APP_KEY when there's none, written to the settings file. */
    public function ensureAppKey(): void
    {
        if (filled(config('app.key'))) {
            return;
        }

        if (! $this->env->writable() || $this->env->managedElsewhere('APP_KEY')) {
            throw new RuntimeException('There\'s no APP_KEY, and '.$this->env->path().' can\'t be written to add one. Run php artisan key:generate, or set APP_KEY where the panel\'s settings are.');
        }

        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
        $this->env->set(['APP_KEY' => $key]);
        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');
        Facade::clearResolvedInstance('encrypter');
    }

    private function record(): void
    {
        $updated = DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
        if ($updated === 0 && DB::table('install_identity')->where('id', 1)->doesntExist()) {
            DB::table('install_identity')->insert(['id' => 1, 'install_id' => (string) Str::uuid(), 'created_at' => now(), 'set_up_at' => now()]);
        }

        $marker = $this->markerPath();
        if (! is_dir(dirname($marker))) {
            @mkdir(dirname($marker), 0700, true);
        }
        @file_put_contents($marker, now()->toIso8601String()."\n");
    }

    private function remember(): void
    {
        try {
            Cache::forever(self::CACHE_KEY, true);
        } catch (Throwable) {
            // The database next time, then.
        }
    }

    private function markerPath(): string
    {
        return SetupCode::directory().'/set-up';
    }
}
