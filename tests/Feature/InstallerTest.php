<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\SelfHostedEntitlements;
use App\Support\Install\AddressCheck;
use App\Support\Install\EnvFile;
use App\Support\Install\Installer;
use App\Support\Install\SetupCode;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A self-hosted install's web installer (App\Support\Install).
 */

/** The installer's settings keys, as the tests' environment has them. */
const INSTALLER_TEST_KEYS = ['APP_KEY', 'APP_URL', 'APP_DOMAIN', 'SESSION_DOMAIN', 'SESSION_SECURE_COOKIE', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'];

/**
 * A new self-hosted install, not set up, with the installer's routes (as
 * at boot) and a settings file of its own. Without $managed, the file has
 * what the tests' environment does, so the installer may change it; keys
 * in $managed are left out, as Docker sets them outside the file.
 *
 * @param  list<string>  $managed
 */
function freshSelfHostedInstall(array $managed = []): string
{
    config(['serveros.edition' => 'self-hosted']);
    app()->forgetInstance(Entitlements::class);
    app()->singleton(Entitlements::class, fn () => new SelfHostedEntitlements);

    if (! app('router')->has('install.show')) {
        require base_path('routes/installer.php');
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    $directory = config('serveros.install.path').'-env';
    File::ensureDirectoryExists($directory);
    $lines = ['# Not the installer’s', 'UNRELATED="keep me as I am"', 'export OTHER=1', ''];
    foreach (INSTALLER_TEST_KEYS as $key) {
        $value = Env::getRepository()->get($key);
        if ($value !== null && ! in_array($key, $managed, true)) {
            $lines[] = $key.'='.EnvFile::quote($value);
        }
    }
    File::put($directory.'/.env', implode("\n", $lines)."\n");
    app()->useEnvironmentPath($directory);
    app()->loadEnvironmentFrom('.env');
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

    // No DNS from tests.
    app()->instance(AddressCheck::class, new AddressCheck(fn (string $host) => str_starts_with($host, 'serveros-check-') ? [] : ['203.0.113.10']));

    return $directory.'/.env';
}

/** Through the setup code and the server check. */
function enterSetupCode(): void
{
    $code = app(SetupCode::class)->current();
    test()->post('/install/code', ['code' => strtolower(str_replace('-', ' ', $code))])->assertRedirect('/install');
}

test('serveros.com never has an installer', function () {
    $this->get('/install')->assertNotFound();
    $this->post('/install/code', ['code' => 'ABCD-EFGH-JKMN'])->assertNotFound();
    // The front page: a package's website, or signing up without one.
    $this->followingRedirects()->get('/')->assertOk();
    expect(request()->path())->not->toStartWith('install');
    $this->artisan('install:code')->assertFailed();

    expect(app(SetupCode::class)->pending())->toBeFalse();
});

test('until it’s set up, every page goes to the installer', function () {
    freshSelfHostedInstall();

    $this->get('/')->assertRedirect('/install');
    $this->get('/login')->assertRedirect('/install');
    $this->get('/dashboard')->assertRedirect('/install');
    $this->post('/register', ['name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertStatus(503);
    $this->getJson('/api/v1/machines')->assertStatus(503);
    // The web server can still check it's up, and get a certificate.
    $this->get('/up')->assertOk();

    $this->get('/install')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('install/Install')
        ->where('step', 'code')
        ->where('codeCommand', 'php artisan install:code'));

    expect(User::query()->count())->toBe(0)
        ->and(app(SetupCode::class)->pending())->toBeTrue();
});

test('the whole install, on SQLite: code, server, database, address, email skipped, owner, finish', function () {
    $envPath = freshSelfHostedInstall();
    $database = config('serveros.install.path').'-db/serveros.sqlite';
    File::ensureDirectoryExists(dirname($database));
    // No database yet, as on a new server.
    config(['database.connections.none' => ['driver' => 'sqlite', 'database' => '/nonexistent/serveros.sqlite', 'prefix' => '']]);
    config(['database.default' => 'none']);

    try {
        $this->get('/install')->assertOk()->assertInertia(fn (Assert $page) => $page->where('step', 'code'));
        enterSetupCode();

        // The server.
        $this->get('/install')->assertInertia(fn (Assert $page) => $page
            ->where('step', 'check')
            ->where('checks', fn ($checks) => collect($checks)->pluck('key')->contains('extensions') && collect($checks)->doesntContain('status', 'fail')));
        $this->post('/install/check')->assertRedirect('/install');

        // The database: not there yet, so its settings.
        $this->get('/install')->assertInertia(fn (Assert $page) => $page
            ->where('step', 'database')
            ->where('database.status.connected', false)
            ->where('database.editable', true));
        $this->postJson('/install/database/test', ['driver' => 'sqlite', 'database' => '/nonexistent/folder/x.sqlite'])
            ->assertJson(['ok' => false]);
        $this->post('/install/database', ['driver' => 'sqlite', 'database' => $database])->assertRedirect('/install?step=database');
        expect(File::get($envPath))->toContain("DB_CONNECTION=sqlite\n")->toContain("DB_DATABASE={$database}\n");

        $this->get('/install')->assertInertia(fn (Assert $page) => $page
            ->where('step', 'database')
            ->where('database.status.connected', true)
            ->where('database.status.ready', false)
            ->where('database.status.pending', fn ($pending) => $pending > 100));

        // Its tables, a line as each is done.
        $lines = collect(explode("\n", trim($this->post('/install/database/migrate')->assertOk()->streamedContent())))
            ->map(fn (string $line) => json_decode($line, true));
        expect($lines->first())->toHaveKey('total')
            ->and($lines->last())->toBe(['finished' => true])
            ->and($lines->where('done', $lines->first()['total'])->count())->toBe(1);

        // The address.
        $this->postJson('/install/address/check', ['url' => 'panel.example.test'])
            ->assertOk()
            ->assertJsonPath('lines.0.text', 'panel.example.test points at 203.0.113.10. Make sure that’s this server.')
            ->assertJsonPath('lines.1.status', 'warn');
        $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('step', 'address')->where('address.editable', true));
        $this->post('/install/address', ['url' => 'https://panel.example.test/some/path'])->assertRedirect('/install');
        expect(File::get($envPath))
            ->toContain("APP_URL=https://panel.example.test\n")
            ->toContain("APP_DOMAIN=panel.example.test\n")
            ->toContain("SESSION_DOMAIN=.panel.example.test\n")
            ->toContain("UNRELATED=\"keep me as I am\"\nexport OTHER=1\n");
        // The next request would read it from .env; the tests' routes are on serveros.test.
        config(['app.url' => 'https://serveros.test', 'app.domain' => 'serveros.test']);

        // Email can wait.
        $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('step', 'mail'));
        $this->post('/install/mail/skip')->assertRedirect('/install');

        // The owner.
        $this->post('/install/owner', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'nope', 'workspace' => 'Acme'])
            ->assertSessionHasErrors('password');
        $this->post('/install/owner', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'workspace' => 'Acme'])
            ->assertRedirect('/install');
        expect(User::query()->count())->toBe(0);

        $this->get('/install')->assertInertia(fn (Assert $page) => $page
            ->where('step', 'done')
            ->where('summary.owner.email', 'ada@example.com')
            ->where('summary.workspace.address', 'acme.serveros.test')
            ->where('summary.mail', null));

        // Finishing.
        $this->post('/install/finish')->assertRedirect(route('dashboard'));

        $owner = User::query()->sole();
        expect($owner->email)->toBe('ada@example.com')
            ->and($owner->hasVerifiedEmail())->toBeTrue()
            ->and(password_verify('password', $owner->password))->toBeTrue()
            ->and($owner->ownedWorkspaces()->sole()->slug)->toBe('acme')
            ->and(DB::table('install_identity')->value('set_up_at'))->not->toBeNull()
            ->and(app(SetupCode::class)->pending())->toBeFalse();
        $this->assertAuthenticatedAs($owner);

        // The installer is gone for good, and sign-ups follow the usual rules.
        $this->get('/install')->assertNotFound();
        $this->post('/install/code', ['code' => 'ABCD-EFGH-JKMN'])->assertNotFound();
        $this->post('/install/finish')->assertNotFound();
        auth()->logout();
        $this->get('/')->assertRedirect(route('login'));
        $this->artisan('install:code')->expectsOutputToContain('set up already')->assertSuccessful();
    } finally {
        // RefreshDatabase rolls back the connection it began with.
        config(['database.default' => 'sqlite']);
    }
});

test('wrong setup codes are limited, five every ten minutes from one address', function () {
    freshSelfHostedInstall();
    $code = app(SetupCode::class)->current();

    foreach (range(1, 5) as $try) {
        $this->post('/install/code', ['code' => 'ABCD-EFGH-JKMN'])->assertSessionHasErrors(['code' => 'That’s not the setup code. Check it and try again.']);
    }
    // Even the right one, until it's waited.
    $this->post('/install/code', ['code' => $code])->assertSessionHasErrors(['code' => 'Too many wrong codes. Try again in 10 minutes.']);
    $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('step', 'code'));

    $this->travel(11)->minutes();
    $this->post('/install/code', ['code' => $code])->assertSessionHasNoErrors();
    $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('step', 'check'));
});

test('no step can be reached out of order', function () {
    freshSelfHostedInstall();
    $owner = ['name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'workspace' => 'Evil'];

    // Without the code, nothing.
    $this->post('/install/check')->assertRedirect('/install')->assertSessionHasErrors(['code' => 'Enter the setup code first.']);
    $this->post('/install/owner', $owner)->assertSessionHasErrors('code');
    $this->post('/install/finish')->assertSessionHasErrors('code');
    $this->postJson('/install/database/test', ['driver' => 'sqlite', 'database' => '/tmp/x.sqlite'])->assertForbidden();
    $this->get('/install?step=owner')->assertInertia(fn (Assert $page) => $page->where('step', 'code'));

    // With it, only the next step.
    enterSetupCode();
    $this->get('/install?step=owner')->assertInertia(fn (Assert $page) => $page->where('step', 'check'));
    $this->post('/install/address', ['url' => 'https://evil.test'])->assertSessionHasErrors(['step' => 'Finish the steps before this one first.']);
    $this->post('/install/owner', $owner)->assertSessionHasErrors('step');
    $this->post('/install/mail/skip')->assertSessionHasErrors('step');
    $this->post('/install/finish')->assertSessionHasErrors('step');
    $this->postJson('/install/database/test', ['driver' => 'sqlite', 'database' => '/tmp/x.sqlite'])->assertStatus(409);
    $this->post('/install/database/migrate')->assertStatus(409);

    // A new code signs out whoever had the old one.
    app(SetupCode::class)->regenerate();
    $this->post('/install/check')->assertSessionHasErrors('code');

    expect(User::query()->count())->toBe(0)
        ->and(session('install.owner'))->toBeNull()
        ->and(DB::table('install_identity')->value('set_up_at'))->toBeNull();
});

test('in Docker the database is already set up, so that step is skipped, and the address is the install’s', function () {
    // compose.yaml sets the database and the address, outside the file.
    $envPath = freshSelfHostedInstall(managed: ['DB_CONNECTION', 'DB_DATABASE', 'APP_URL', 'APP_DOMAIN']);
    config(['serveros.install.docker' => true]);

    $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('codeCommand', 'serveros-panel setup-code'));
    enterSetupCode();
    $this->post('/install/check')->assertRedirect('/install');

    // Straight on to the address: the database is there with its tables.
    $this->get('/install')->assertInertia(fn (Assert $page) => $page
        ->where('step', 'address')
        ->where('progress.database', true)
        ->where('address.editable', false));
    $this->get('/install?step=database')->assertInertia(fn (Assert $page) => $page
        ->where('database.status.ready', true)
        ->where('database.editable', false));
    $this->post('/install/database', ['driver' => 'sqlite', 'database' => '/tmp/x.sqlite'])->assertSessionHasErrors('connection');

    $this->post('/install/address', ['url' => 'https://elsewhere.test'])->assertRedirect('/install');
    $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('step', 'mail'));

    expect(File::get($envPath))->not->toContain('DB_DATABASE=/tmp')->not->toContain('elsewhere.test');
});

test('email is tried, then saved to .env, and its password never comes back to the page', function () {
    $envPath = freshSelfHostedInstall();
    enterSetupCode();
    $this->post('/install/check');
    $this->post('/install/address', ['url' => 'https://panel.example.test']);
    config(['app.url' => 'https://serveros.test', 'app.domain' => 'serveros.test']);

    $this->postJson('/install/mail/test', ['host' => 'smtp.example.test', 'port' => 587, 'from_address' => 'not an address', 'to' => 'ada@example.com'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    $this->post('/install/mail', [
        'host' => 'smtp.example.test',
        'port' => 465,
        'username' => 'serveros',
        'mail_password' => ' s3cret "with" $quotes ',
        'from_address' => 'serveros@example.test',
        'from_name' => 'Our ServerOS',
    ])->assertRedirect('/install');

    $saved = (new EnvFile($envPath))->values();
    expect($saved)->toMatchArray([
        'MAIL_MAILER' => 'smtp',
        'MAIL_SCHEME' => 'smtps',
        'MAIL_HOST' => 'smtp.example.test',
        'MAIL_PORT' => '465',
        'MAIL_PASSWORD' => ' s3cret "with" $quotes ',
        'MAIL_FROM_NAME' => 'Our ServerOS',
        'UNRELATED' => 'keep me as I am',
    ]);

    config(['mail.mailers.smtp.password' => ' s3cret "with" $quotes ']);
    $page = $this->get('/install?step=mail')->assertInertia(fn (Assert $page) => $page
        ->where('step', 'mail')
        ->where('mail.hasPassword', true)
        ->missing('mail.password'));
    expect($page->getContent())->not->toContain('s3cret');
});

test('writing .env changes only the installer’s keys, keeps everything else, and is for its owner only', function () {
    $path = sys_get_temp_dir().'/serveros-env-'.bin2hex(random_bytes(4));
    File::put($path, implode("\n", [
        '# ServerOS',
        'APP_NAME="My Panel"',
        'APP_KEY=base64:abc=',
        '',
        '# The database',
        'DB_CONNECTION=mysql',
        'export DB_HOST=old.example.test',
        'DB_PASSWORD=old',
        'DB_PASSWORD=older',
        'UNRELATED=\'single # quoted\'',
        'MAIL_MAILER=log',
    ])."\n");
    chmod($path, 0644);

    $env = new EnvFile($path);
    $env->set([
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'db.internal',
        'DB_PASSWORD' => 'p@ss w\'ord "$HOME" \\ #1',
        'DB_PORT' => '5432',
    ]);

    $contents = File::get($path);
    $lines = explode("\n", $contents);
    expect($env->values())->toMatchArray([
        'APP_NAME' => 'My Panel',
        'APP_KEY' => 'base64:abc=',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'db.internal',
        'DB_PASSWORD' => 'p@ss w\'ord "$HOME" \\ #1',
        'DB_PORT' => '5432',
        'UNRELATED' => 'single # quoted',
        'MAIL_MAILER' => 'log',
    ])
        // Every other line exactly as it was, in its place; new keys at the end.
        ->and(array_slice($lines, 0, 5))->toBe(['# ServerOS', 'APP_NAME="My Panel"', 'APP_KEY=base64:abc=', '', '# The database'])
        ->and($lines[5])->toBe('DB_CONNECTION=pgsql')
        ->and($lines[6])->toBe('DB_HOST=db.internal')
        ->and($lines[7])->toBe('DB_PASSWORD=old')
        ->and($lines[9])->toBe("UNRELATED='single # quoted'")
        ->and($lines[10])->toBe('MAIL_MAILER=log')
        ->and(end($lines))->toBe('')
        ->and($lines[count($lines) - 2])->toBe('DB_PORT=5432')
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(fn () => $env->set(['DB_HOST' => "evil\nAPP_KEY=x"]))->toThrow(RuntimeException::class)
        ->and(fn () => $env->set(['not a key' => 'x']))->toThrow(RuntimeException::class);

    File::delete($path);
});

test('the setup code is random, kept only as a hash and an encrypted copy, and the same until it’s used', function () {
    freshSelfHostedInstall();
    $code = app(SetupCode::class)->current();

    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and(app(SetupCode::class)->current())->toBe($code)
        ->and(app(SetupCode::class)->matches(strtolower($code)))->toBeTrue()
        ->and(app(SetupCode::class)->matches('ABCD-EFGH-JKMN'))->toBeFalse()
        ->and(File::get(app(SetupCode::class)->path()))->not->toContain(str_replace('-', '', $code))->not->toContain($code)
        ->and(fileperms(app(SetupCode::class)->path()) & 0777)->toBe(0600);

    $this->artisan('install:code --plain')->expectsOutput($code)->assertSuccessful();
    $this->artisan('install:code')->expectsOutputToContain($code)->assertSuccessful();
    $this->artisan('install:code --new --plain')->doesntExpectOutput($code)->assertSuccessful();
    expect(app(SetupCode::class)->matches($code))->toBeFalse();
});

test('an install that already has accounts is set up, so it has no installer', function () {
    freshSelfHostedInstall();
    app(SetupCode::class)->current();
    $user = User::factory()->create();

    $this->get('/install')->assertNotFound();
    $this->post('/install/code', ['code' => 'ABCD-EFGH-JKMN'])->assertNotFound();
    $this->get('/')->assertRedirect(route('login'));
    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));

    expect(DB::table('install_identity')->value('set_up_at'))->not->toBeNull()
        ->and(app(SetupCode::class)->pending())->toBeFalse()
        ->and(app(Installer::class)->isSetUp())->toBeTrue();
});

test('while a setup code is waiting, the first sign-up isn’t made the owner', function () {
    freshSelfHostedInstall();
    app(SetupCode::class)->current();

    $user = app(CreateNewUser::class)->create(['name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('the installer makes an APP_KEY when there’s none, before anything else', function () {
    $envPath = freshSelfHostedInstall();
    $before = config('app.key');
    config(['app.key' => '']);

    $this->get('/install')->assertOk();

    expect(config('app.key'))->toStartWith('base64:')->not->toBe($before)
        ->and((new EnvFile($envPath))->get('APP_KEY'))->toBe(config('app.key'));
});
