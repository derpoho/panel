<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\CreateWorkspace;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Middleware\EnsureInstalling;
use App\Models\User;
use App\Support\Install\AddressCheck;
use App\Support\Install\DatabaseSetup;
use App\Support\Install\EnvFile;
use App\Support\Install\Installer;
use App\Support\Install\MailSetup;
use App\Support\Install\ServerChecks;
use App\Support\Install\SetupCode;
use App\Support\WorkspaceSlugs;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A self-hosted install's web installer, one step at a time: the setup
 * code, the server check, the database, the panel's address, email
 * (optional) and the owner's account, then finishing, which records the
 * install as set up and opens the dashboard. Each step needs the code (in
 * this session) and the steps before it; nothing typed into a password
 * field is sent back to the page, flashed to the session or logged.
 * App\Support\Install says more.
 */
class InstallController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    /** Wrong setup codes one address may try in ten minutes. */
    public const CODE_TRIES = 5;

    public function __construct(
        private Installer $installer,
        private SetupCode $code,
        private EnvFile $env,
        private DatabaseSetup $database,
    ) {}

    public function show(Request $request, ServerChecks $checks): Response
    {
        $progress = $this->progress($request);
        $current = $this->current($progress);

        // There's always a code to enter until it's set up.
        if (! $this->code->pending()) {
            $this->code->regenerate();
        }

        $asked = $request->query('step');
        $step = is_string($asked) && in_array($asked, Installer::STEPS, true) && $progress['code']
            && array_search($asked, Installer::STEPS, true) <= array_search($current, Installer::STEPS, true)
            ? $asked
            : $current;

        return Inertia::render('install/Install', [
            'step' => $step,
            'current' => $current,
            'progress' => $progress,
            'docker' => Installer::docker(),
            'codeCommand' => Installer::docker() ? 'serveros-panel setup-code' : 'php artisan install:code',
            ...match ($step) {
                'check' => ['checks' => $checks->run()],
                'database' => ['database' => $this->databaseProps()],
                'address' => ['address' => $this->addressProps($request)],
                'mail' => ['mail' => $this->mailProps()],
                'owner' => ['owner' => $this->ownerProps($request)],
                'done' => ['summary' => $this->summary($request)],
                default => [],
            },
        ]);
    }

    public function code(Request $request): RedirectResponse
    {
        // On the installer's own cache (Installer::prepare): the app's
        // limiter was made with the panel's, which may be the database.
        $limiter = new RateLimiter(Cache::store());
        $limit = 'install-code:'.$request->ip();
        if ($limiter->tooManyAttempts($limit, self::CODE_TRIES)) {
            $minutes = max(1, (int) ceil($limiter->availableIn($limit) / 60));

            return redirect('/install')->withErrors(['code' => trans_choice('Too many wrong codes. Try again in :count minute.|Too many wrong codes. Try again in :count minutes.', $minutes, ['count' => $minutes])]);
        }

        $given = $request->input('code');
        if (! $this->code->matches(is_string($given) ? $given : null)) {
            $limiter->hit($limit, 600);

            return redirect('/install')->withErrors(['code' => 'That’s not the setup code. Check it and try again.']);
        }

        $limiter->clear($limit);
        $request->session()->regenerate();
        $request->session()->put(EnsureInstalling::SESSION_KEY, $this->code->fingerprint());

        return redirect('/install');
    }

    public function check(Request $request, ServerChecks $checks): SymfonyResponse
    {
        if (($refused = $this->before($request, 'check')) !== null) {
            return $refused;
        }

        if (! ServerChecks::passed($checks->run())) {
            return redirect('/install?step=check')->withErrors(['check' => 'Fix what’s marked first, then check again.']);
        }

        $request->session()->put('install.done.check', true);

        return redirect('/install');
    }

    public function testDatabase(Request $request): SymfonyResponse
    {
        if (($refused = $this->before($request, 'database')) !== null) {
            return $refused;
        }

        $validator = $this->databaseValidator($request);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => $validator->errors()->first()], 422);
        }

        $result = $this->database->test($this->databaseSettings($validator->validated()));

        return response()->json($result['error'] === null
            ? ['ok' => true, 'message' => 'Connected to '.DatabaseSetup::LABELS[$result['driver']].'.']
            : ['ok' => false, 'message' => $result['error']]);
    }

    public function saveDatabase(Request $request): SymfonyResponse
    {
        if (($refused = $this->before($request, 'database')) !== null) {
            return $refused;
        }
        if (! $this->databaseEditable()) {
            return redirect('/install?step=database')->withErrors(['connection' => 'These settings come from outside the panel ('.$this->env->path().' can’t change them), so the installer can’t change the database.']);
        }

        $validator = $this->databaseValidator($request);
        if ($validator->fails()) {
            return redirect('/install?step=database')->withErrors($validator);
        }

        $settings = $this->databaseSettings($validator->validated());
        $result = $this->database->test($settings);
        if ($result['error'] !== null) {
            return redirect('/install?step=database')->withErrors(['connection' => $result['error']]);
        }
        $settings['driver'] = $result['driver'];

        $values = DatabaseSetup::envValues($settings);
        if ($this->env->get('DB_URL') !== null) {
            $values['DB_URL'] = '';
        }
        $this->save($values);
        $this->database->use($settings);

        return redirect('/install?step=database');
    }

    /**
     * Set up the database's tables, reporting as it goes: a line of JSON
     * when it starts ({"total": n}), one as each migration finishes
     * ({"done": i}), and {"finished": true} or {"error": "..."} at the end.
     */
    public function migrate(Request $request): SymfonyResponse
    {
        if (($refused = $this->before($request, 'database')) !== null) {
            return $refused;
        }

        $status = $this->databaseStatus();
        if (! $status['connected']) {
            return response()->json(['message' => $status['error']], 422);
        }
        $total = count($this->database->pending());

        return new StreamedResponse(function () use ($total): void {
            $send = function (array $line): void {
                echo json_encode($line)."\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            $send(['total' => $total]);
            $done = 0;
            Event::listen(MigrationEnded::class, function () use (&$done, $send): void {
                $send(['done' => ++$done]);
            });

            try {
                Artisan::call('migrate', ['--force' => true, '--database' => (string) config('database.default')]);
                $send(['finished' => true]);
            } catch (Throwable $e) {
                $send(['error' => 'Setting up the tables stopped: '.(strtok($e->getMessage(), "\n") ?: 'something went wrong').' Fix that, then try again: it carries on where it stopped.']);
            }
        }, 200, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    public function checkAddress(Request $request, AddressCheck $check): SymfonyResponse
    {
        if (($refused = $this->before($request, 'address')) !== null) {
            return $refused;
        }

        $url = self::normalizeUrl($request->input('url'));
        if ($url === null) {
            return response()->json(['status' => 'fail', 'lines' => [['status' => 'fail', 'text' => 'That isn’t a web address. Use one like https://panel.example.com.']]], 422);
        }

        return response()->json($check->check((string) parse_url($url, PHP_URL_HOST), $request));
    }

    public function saveAddress(Request $request): SymfonyResponse
    {
        if (($refused = $this->before($request, 'address')) !== null) {
            return $refused;
        }

        if ($this->addressEditable()) {
            $url = self::normalizeUrl($request->input('url'));
            if ($url === null) {
                return redirect('/install?step=address')->withErrors(['url' => 'That isn’t a web address. Use one like https://panel.example.com.']);
            }

            $host = (string) parse_url($url, PHP_URL_HOST);
            $local = $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
            $values = array_filter([
                'APP_URL' => $url,
                'APP_DOMAIN' => $host,
                // Signed in on the panel means signed in on its workspaces' subdomains.
                'SESSION_DOMAIN' => $local ? 'null' : '.'.$host,
                'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https://') ? 'true' : 'false',
            ], fn (string $value, string $key) => ! $this->env->managedElsewhere($key), ARRAY_FILTER_USE_BOTH);
            $this->save($values);
            config(['app.url' => $url, 'app.domain' => $host]);
        }

        $request->session()->put('install.done.address', true);

        return redirect('/install');
    }

    public function testMail(Request $request, MailSetup $mail): SymfonyResponse
    {
        if (($refused = $this->before($request, 'mail')) !== null) {
            return $refused;
        }

        $validator = $this->mailValidator($request, withRecipient: true);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => $validator->errors()->first()], 422);
        }

        $settings = $this->mailSettings($validator->validated());
        $error = $mail->test($settings, (string) $validator->validated()['to']);

        return response()->json($error === null
            ? ['ok' => true, 'message' => 'Sent. Check '.$validator->validated()['to'].' (and its spam folder).']
            : ['ok' => false, 'message' => $error]);
    }

    public function saveMail(Request $request): SymfonyResponse
    {
        if (($refused = $this->before($request, 'mail')) !== null) {
            return $refused;
        }
        if (! $this->mailEditable()) {
            return redirect('/install?step=mail')->withErrors(['host' => 'Email is set outside the panel, so the installer can’t change it.']);
        }

        $validator = $this->mailValidator($request);
        if ($validator->fails()) {
            return redirect('/install?step=mail')->withErrors($validator);
        }

        $settings = $this->mailSettings($validator->validated());
        $values = MailSetup::envValues($settings);
        // Left empty: keep the one saved before, if there is one.
        if (! filled($validator->validated()['mail_password'] ?? null)) {
            unset($values['MAIL_PASSWORD']);
        }
        $this->save($values);
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $values['MAIL_FROM_NAME'],
        ]);

        $request->session()->put('install.done.mail', true);

        return redirect('/install');
    }

    public function skipMail(Request $request): SymfonyResponse
    {
        if (($refused = $this->before($request, 'mail')) !== null) {
            return $refused;
        }

        $request->session()->put('install.done.mail', true);

        return redirect('/install');
    }

    public function saveOwner(Request $request, WorkspaceSlugs $slugs): SymfonyResponse
    {
        if (($refused = $this->before($request, 'owner')) !== null) {
            return $refused;
        }

        $validator = Validator::make($request->all(), [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'workspace' => ['required', 'string', 'max:64'],
        ], [], ['workspace' => 'workspace name']);
        if ($validator->fails()) {
            return redirect('/install?step=owner')->withErrors($validator);
        }

        $owner = $validator->validated();
        $request->session()->put('install.owner', [
            'name' => $owner['name'],
            'email' => $owner['email'],
            'workspace' => $owner['workspace'],
            'slug' => $slugs->generate($owner['workspace'], $owner['email']),
            // Only ever its hash, until the account is made from it.
            'password' => bcrypt($owner['password']),
        ]);

        return redirect('/install');
    }

    public function finish(Request $request, CreateWorkspace $createWorkspace): SymfonyResponse
    {
        if (($refused = $this->before($request, 'done')) !== null) {
            return $refused;
        }

        /** @var array{name: string, email: string, workspace: string, slug: string, password: string} $owner */
        $owner = $request->session()->get('install.owner');
        if (User::query()->where('email', $owner['email'])->exists()) {
            return redirect('/install?step=owner')->withErrors(['email' => 'There’s an account with that email already.']);
        }

        $user = DB::transaction(function () use ($owner, $createWorkspace): User {
            $user = User::create(['name' => $owner['name'], 'email' => $owner['email'], 'password' => $owner['password']]);
            // The owner starts verified: email may not be set up yet.
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
            $createWorkspace->create($user, $owner['slug'], $owner['workspace']);

            return $user;
        });
        $this->installer->markSetUp();

        // Signed in on the panel's own session, not the installer's.
        $request->session()->forget(['install', EnsureInstalling::SESSION_KEY]);
        $this->installer->handOver($request);
        Auth::guard('web')->login($user);
        Cookie::queue(Cookie::forget(Installer::SESSION_COOKIE));

        // Workers started before these settings were saved pick them up.
        rescue(fn () => Artisan::call('queue:restart'), report: false);

        return Inertia::location(route('dashboard'));
    }

    /**
     * Where each step is: done, or not yet. A step only counts once the ones
     * before it do; the database counts once it's reachable and has its tables.
     *
     * @return array<string, bool>
     */
    private function progress(Request $request): array
    {
        $session = $request->session();
        $progress = ['code' => EnsureInstalling::entered($request, $this->code)];
        $progress['check'] = $progress['code'] && (bool) $session->get('install.done.check');
        $progress['database'] = $progress['check'] && $this->databaseStatus()['ready'];
        $progress['address'] = $progress['database'] && (bool) $session->get('install.done.address');
        $progress['mail'] = $progress['address'] && (bool) $session->get('install.done.mail');
        $progress['owner'] = $progress['mail'] && is_array($session->get('install.owner'));
        $progress['done'] = false;

        return $progress;
    }

    /** @param  array<string, bool>  $progress */
    private function current(array $progress): string
    {
        return (string) array_search(false, $progress, true);
    }

    /**
     * Null when every step before this one is done; otherwise the answer
     * that sends them back to where they are.
     */
    private function before(Request $request, string $step): RedirectResponse|JsonResponse|null
    {
        $progress = $this->progress($request);
        $index = array_search($step, Installer::STEPS, true);
        foreach (array_slice(Installer::STEPS, 0, (int) $index) as $earlier) {
            if (! $progress[$earlier]) {
                return $request->expectsJson() || $request->routeIs('install.database.migrate')
                    ? response()->json(['message' => 'Finish the steps before this one first.'], 409)
                    : redirect('/install')->withErrors(['step' => 'Finish the steps before this one first.']);
            }
        }

        return null;
    }

    /**
     * The database's state, once per request (the controller can outlive one).
     *
     * @return array{connected: bool, error: string|null, driver: string|null, pending: int, ready: bool}
     */
    private function databaseStatus(): array
    {
        $request = request();
        if (! $request->attributes->has('install.database')) {
            $request->attributes->set('install.database', $this->database->status());
        }

        /** @var array{connected: bool, error: string|null, driver: string|null, pending: int, ready: bool} */
        return $request->attributes->get('install.database');
    }

    /** @param  array<string, string>  $values */
    private function save(array $values): void
    {
        $this->env->set($values);

        // Cached settings would hide what was just saved.
        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }
    }

    private function databaseEditable(): bool
    {
        return $this->env->writable() && collect(DatabaseSetup::KEYS)->doesntContain(fn (string $key) => $this->env->managedElsewhere($key));
    }

    private function addressEditable(): bool
    {
        return $this->env->writable() && ! $this->env->managedElsewhere('APP_URL');
    }

    private function mailEditable(): bool
    {
        return $this->env->writable() && collect(MailSetup::KEYS)->doesntContain(fn (string $key) => $this->env->managedElsewhere($key));
    }

    private function databaseValidator(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'driver' => ['required', Rule::in(['pgsql', 'mysql', 'sqlite'])],
            'host' => ['exclude_if:driver,sqlite', 'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:_\[\]]+$/'],
            'port' => ['exclude_if:driver,sqlite', 'nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\0]/'],
            'username' => ['exclude_if:driver,sqlite', 'nullable', 'string', 'max:255', 'not_regex:/[\r\n\0]/'],
            'db_password' => ['exclude_if:driver,sqlite', 'nullable', 'string', 'max:1024', 'not_regex:/[\r\n\0]/'],
        ], [
            'host.regex' => 'That isn’t a host name or address.',
        ], ['db_password' => 'password']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{driver: string, host?: string|null, port?: string|int|null, database: string, username?: string|null, password?: string|null}
     */
    private function databaseSettings(array $input): array
    {
        $driver = (string) $input['driver'];
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => (string) $input['database']];
        }

        $password = $input['db_password'] ?? null;
        // Left empty: the one saved before, if there is one.
        if (! filled($password)) {
            $password = $this->env->get('DB_PASSWORD');
        }

        return [
            'driver' => $driver,
            'host' => (string) $input['host'],
            'port' => isset($input['port']) ? (int) $input['port'] : ($driver === 'pgsql' ? 5432 : 3306),
            'database' => (string) $input['database'],
            'username' => isset($input['username']) ? (string) $input['username'] : '',
            'password' => is_string($password) ? $password : '',
        ];
    }

    private function mailValidator(Request $request, bool $withRecipient = false): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:_\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\0]/'],
            'mail_password' => ['nullable', 'string', 'max:1024', 'not_regex:/[\r\n\0]/'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n\0]/'],
            ...($withRecipient ? ['to' => ['required', 'email', 'max:255']] : []),
        ], [
            'host.regex' => 'That isn’t a host name or address.',
        ], ['mail_password' => 'password', 'from_address' => 'from address', 'to' => 'address to send the test to']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{host: string, port: int, username: string|null, password: string|null, from_address: string, from_name: string|null}
     */
    private function mailSettings(array $input): array
    {
        $password = $input['mail_password'] ?? null;
        if (! filled($password)) {
            $saved = config('mail.mailers.smtp.password');
            $password = is_string($saved) && $saved !== '' ? $saved : null;
        }

        return [
            'host' => (string) $input['host'],
            'port' => (int) $input['port'],
            'username' => isset($input['username']) ? (string) $input['username'] : null,
            'password' => is_string($password) ? $password : null,
            'from_address' => (string) $input['from_address'],
            'from_name' => isset($input['from_name']) ? (string) $input['from_name'] : null,
        ];
    }

    /** An address as the panel keeps it: scheme, host and port, nothing after. */
    private static function normalizeUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $valid = filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) === 1;
        if (! in_array($scheme, ['http', 'https'], true) || ! $valid || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return array<string, mixed> */
    private function databaseProps(): array
    {
        $name = (string) config('database.default');
        /** @var array<string, mixed> $connection */
        $connection = (array) config("database.connections.{$name}");
        $driver = (string) ($connection['driver'] ?? 'pgsql');

        return [
            'status' => $this->databaseStatus(),
            'editable' => $this->databaseEditable(),
            'settings' => [
                'driver' => $driver === 'mariadb' ? 'mysql' : $driver,
                'label' => DatabaseSetup::LABELS[$driver] ?? $driver,
                'host' => $driver === 'sqlite' ? null : ($connection['host'] ?? null),
                'port' => $driver === 'sqlite' ? null : ($connection['port'] ?? null),
                'database' => $connection['database'] ?? null,
                'username' => $driver === 'sqlite' ? null : ($connection['username'] ?? null),
            ],
            'hasPassword' => filled($connection['password'] ?? null),
            'drivers' => collect(['pgsql', 'mysql', 'sqlite'])->mapWithKeys(fn (string $driver) => [$driver => extension_loaded(DatabaseSetup::EXTENSIONS[$driver])])->all(),
            'sqlitePath' => database_path('database.sqlite'),
        ];
    }

    /** @return array<string, mixed> */
    private function addressProps(Request $request): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $visiting = $request->getSchemeAndHttpHost();

        return [
            'url' => $url,
            // The address they opened the installer on, when .env still has Laravel's.
            'suggested' => in_array($url, ['', 'http://localhost', 'http://localhost:8000'], true) ? $visiting : $url,
            'editable' => $this->addressEditable(),
        ];
    }

    /** @return array<string, mixed> */
    private function mailProps(): array
    {
        $smtp = (array) config('mail.mailers.smtp');
        $saved = config('mail.default') === 'smtp';

        return [
            'editable' => $this->mailEditable(),
            'saved' => $saved,
            'host' => $saved ? ($smtp['host'] ?? null) : null,
            'port' => $saved ? ($smtp['port'] ?? null) : null,
            'username' => $saved ? ($smtp['username'] ?? null) : null,
            'hasPassword' => $saved && filled($smtp['password'] ?? null),
            'fromAddress' => $saved ? config('mail.from.address') : null,
            'fromName' => config('mail.from.name') ?: 'ServerOS',
        ];
    }

    /** @return array<string, mixed> */
    private function ownerProps(Request $request): array
    {
        $saved = $request->session()->get('install.owner');

        return [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'saved' => is_array($saved) ? ['name' => $saved['name'], 'email' => $saved['email'], 'workspace' => $saved['workspace']] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Request $request): array
    {
        /** @var array{name: string, email: string, workspace: string, slug: string} $owner */
        $owner = $request->session()->get('install.owner');
        $connection = (array) config('database.connections.'.config('database.default'));
        $driver = (string) ($connection['driver'] ?? '');
        $domain = (string) config('app.domain');

        return [
            'address' => rtrim((string) config('app.url'), '/'),
            'database' => (DatabaseSetup::LABELS[$driver] ?? $driver).($driver === 'sqlite' ? '' : ', '.($connection['database'] ?? '').' on '.($connection['host'] ?? '')),
            'mail' => config('mail.default') === 'smtp'
                ? 'From '.config('mail.from.address').', through '.config('mail.mailers.smtp.host')
                : null,
            'owner' => ['name' => $owner['name'], 'email' => $owner['email']],
            'workspace' => ['name' => $owner['workspace'], 'address' => $owner['slug'].'.'.$domain],
        ];
    }
}
