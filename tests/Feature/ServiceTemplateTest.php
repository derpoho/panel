<?php

use App\Models\AppInstall;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function templateMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/deploy'];
}

/** @return array<string, mixed> */
function sentJob(): array
{
    $command = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->first(fn (GatewayRequest $request) => str_ends_with($request->url(), '/commands'));

    expect($command)->not->toBeNull();

    return json_decode($command->body(), true)['command']['job'];
}

test('the catalog has four hundred and forty-seven valid templates and every compose variable is provided', function () {
    $templates = app(ServiceTemplateCatalog::class)->all();

    expect($templates)->toHaveCount(447);

    foreach ($templates as $template) {
        expect(in_array($template->category, ServiceTemplate::CATEGORIES, true))->toBeTrue();

        if ($template->isApp()) {
            expect($template->dockerfile)->not->toBeNull();

            if ($template->dockerfile !== 'Dockerfile') {
                expect($template->files)->toHaveKey($template->dockerfile);
            }

            continue;
        }

        preg_match_all('/\$\{([A-Z0-9_]+)\}/', $template->files['compose.yaml'], $m);
        $provided = [...array_column($template->env, 'key'), 'PORT', 'DOCKER_BRIDGE'];

        expect($template->files['compose.yaml'])->not->toContain('172.17.0.1', "{$template->slug} hardcodes Docker's bridge");

        expect(array_diff(array_unique($m[1]), $provided))
            ->toBe([], "{$template->slug} uses variables it never sets");
    }
});

test('a database template deploys its compose file with a generated password', function () {
    [$workspace, $machine, $url] = templateMachine();

    $response = $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'postgres',
        'service' => 'main-db',
        'port' => 5433,
    ])->assertAccepted();

    $job = sentJob();

    expect($job['repo'])->toBe('')
        ->and($job['compose_file'])->toBe('compose.yaml')
        ->and($job['files'])->toHaveKey('compose.yaml')
        ->and($job['commit'])->toMatch('/^[a-f0-9]{40}$/')
        ->and($job['port'])->toBe(5433)
        ->and($job['env']['PORT'])->toBe('5433')
        ->and($job['env']['POSTGRES_USER'])->toBe('app')
        ->and(strlen($job['env']['POSTGRES_PASSWORD']))->toBe(24)
        ->and($job['health'])->toBeNull()
        ->and($job['domains'])->toBe([]);

    expect($response->json('generated.0.key'))->toBe('POSTGRES_PASSWORD')
        ->and($response->json('generated.0.value'))->toBe($job['env']['POSTGRES_PASSWORD']);
});

test('Supabase gets its anon and service role keys signed with the JWT secret it generates', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'supabase', 'service' => 'supabase'])->assertAccepted();

    $env = sentJob()['env'];
    $claims = function (string $jwt) use ($env): array {
        [$header, $payload, $signature] = explode('.', $jwt);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $env['JWT_SECRET'], true)), '+/', '-_'), '=');
        expect(hash_equals($expected, $signature))->toBeTrue();

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    };

    expect(strlen($env['JWT_SECRET']))->toBeGreaterThanOrEqual(32)
        ->and($claims($env['ANON_KEY']))->toMatchArray(['role' => 'anon', 'iss' => 'supabase'])
        ->and($claims($env['SERVICE_ROLE_KEY']))->toMatchArray(['role' => 'service_role'])
        ->and($claims($env['ANON_KEY'])['exp'])->toBeGreaterThan(now()->addYears(9)->timestamp);
});

test('an install from the app installer is recorded with its app, for the admin Stats', function () {
    [$workspace, $machine, $url] = templateMachine();

    $response = $this->actingAs($workspace->owner)->postJson($url, ['template' => 'postgres', 'service' => 'main-db'])->assertAccepted();

    $install = AppInstall::query()->sole();
    expect($install->template)->toBe('postgres')
        ->and($install->workspace_id)->toBe($workspace->id)
        ->and($install->machine_id)->toBe($machine->id)
        ->and($install->daemon_job_id)->toBe($response->json('jobId'))
        ->and($install->source)->toBe('panel');
});

test('stacks publish to apps on the machine at its own Docker bridge, and the notes say where', function () {
    [$workspace, $machine, $url] = templateMachine();
    $machine->forceFill(['facts' => ['interfaces' => [
        ['name' => 'eth0', 'addresses' => ['203.0.113.7']],
        ['name' => 'docker0', 'addresses' => ['172.18.0.1/16', 'fe80::1']],
    ]]])->save();

    $response = $this->actingAs($workspace->owner)->postJson($url, ['template' => 'postgres', 'service' => 'main-db'])->assertAccepted();

    expect(sentJob()['files']['compose.yaml'])->toContain('"${DOCKER_BRIDGE}:${PORT}:5432"')
        ->and(sentJob()['env']['DOCKER_BRIDGE'])->toBe('172.18.0.1')
        ->and($response->json('notes'))->toContain('connect through 172.18.0.1 instead of 127.0.0.1');

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines/'.$machine->id)
        ->assertInertia(fn ($page) => $page->where('templates', fn ($templates) => str_contains(
            (string) collect($templates)->firstWhere('slug', 'postgres')['notes'], '172.18.0.1'
        ))->etc());

    $machine->forceFill(['facts' => []])->save();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'postgres', 'service' => 'other-db'])->assertAccepted();
    expect(json_decode(collect(Http::recorded())->last()[0]->body(), true)['command']['job']['env']['DOCKER_BRIDGE'])->toBe('172.17.0.1');
});

test('on a local test node a web service opens through its Caddy, with no tunnel', function () {
    [$workspace, $machine, $url] = templateMachine();
    config()->set('daemon.local_node_port', '8088');
    $machine->update(['ip_address' => '127.0.0.1']);

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'wordpress', 'service' => 'blog'])
        ->assertAccepted()
        ->assertJson([
            'url' => 'http://8080.localhost:8088',
            'tunnel' => null,
        ]);

    expect(sentJob()['env']['WP_URL'])->toBe('http://8080.localhost:8088');
});

test('a real machine on 127.0.0.1 gets no local address unless the local node port is set', function () {
    config()->set('daemon.local_node_port', null);
    $machine = Machine::factory()->create(['ip_address' => '127.0.0.1']);

    expect($machine->localAddress(3000))->toBeNull();

    config()->set('daemon.local_node_port', '8088');
    expect($machine->localAddress(3000))->toBe('http://3000.localhost:8088');

    $machine->ip_address = '203.0.113.7';
    expect($machine->localAddress(3000))->toBeNull();
});

test('WordPress installs itself with an admin account at its domain', function () {
    [$workspace, , $url] = templateMachine();

    $response = $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'wordpress',
        'service' => 'blog',
        'domain' => 'blog.example.com',
        'env' => ['WP_TITLE' => 'Field notes'],
    ])->assertAccepted();

    $job = sentJob();

    expect($job['domains'])->toBe(['blog.example.com'])
        ->and($job['env']['WP_URL'])->toBe('https://blog.example.com')
        ->and($job['env']['WP_TITLE'])->toBe('Field notes')
        ->and($job['env']['WP_ADMIN_USER'])->toBe('admin')
        ->and(strlen($job['env']['WP_ADMIN_PASSWORD']))->toBe(20)
        ->and($job['files']['compose.yaml'])->toContain('wp_install(');

    expect(array_column($response->json('generated'), 'key'))->toContain('WP_ADMIN_PASSWORD', 'DB_PASSWORD');
});

test('an app template builds the repository with our Dockerfile behind a domain', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'laravel',
        'service' => 'shop',
        'repo' => 'https://github.com/example/shop.git',
        'commit' => str_repeat('b', 40),
        'domain' => 'shop.example.com',
        'env' => ['DB_CONNECTION' => 'pgsql'],
        'extra_env' => "MAIL_MAILER=log\n# comments are skipped",
    ])->assertAccepted();

    $job = sentJob();

    expect($job['repo'])->toBe('https://github.com/example/shop.git')
        ->and($job['dockerfile'])->toBe('.serveros/Dockerfile')
        ->and($job['files'])->toHaveKey('.serveros/Dockerfile')
        ->and($job['port'])->toBe(8080)
        ->and($job['health']['path'])->toBe('/up')
        ->and($job['domains'])->toBe(['shop.example.com'])
        ->and($job['env']['APP_URL'])->toBe('https://shop.example.com')
        ->and($job['env']['APP_KEY'])->toStartWith('base64:')
        ->and($job['env']['DB_CONNECTION'])->toBe('pgsql')
        ->and($job['env']['MAIL_MAILER'])->toBe('log');
});

test('a Discord bot builds from its repository with the token, and no web page or domain to check', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'discord-js',
        'service' => 'bot',
        'repo' => 'https://github.com/example/bot.git',
        'commit' => str_repeat('e', 40),
        'domain' => 'bot.example.com',
        'env' => ['DISCORD_TOKEN' => 'secret-token'],
    ])->assertAccepted();

    $job = sentJob();

    expect($job['dockerfile'])->toBe('.serveros/Dockerfile')
        ->and($job['health'])->toBeNull()
        ->and($job['domains'])->toBe([])
        ->and($job['env']['DISCORD_TOKEN'])->toBe('secret-token')
        ->and($job['env']['START_COMMAND'])->toBe('npm start');
});

test('a Discord bot needs its token', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'discord-py',
        'service' => 'bot',
        'repo' => 'https://github.com/example/bot.git',
        'commit' => str_repeat('e', 40),
    ])->assertUnprocessable()->assertJsonValidationErrors('env.DISCORD_TOKEN');
});

test('public build variables reach the build, secrets stay at runtime only', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'nextjs',
        'service' => 'web',
        'repo' => 'https://github.com/example/web.git',
        'commit' => str_repeat('d', 40),
        'extra_env' => "NEXT_PUBLIC_API_URL=https://api.example.com\nNEXT_PUBLIC_NAME=Say \"hi\"\nDATABASE_URL=postgres://secret@db/app",
    ])->assertAccepted();

    $job = sentJob();
    $build = $job['files']['.env.production.local'] ?? '';

    expect($build)->toContain('NEXT_PUBLIC_API_URL="https://api.example.com"')
        ->toContain('NEXT_PUBLIC_NAME="Say \\"hi\\""')
        ->not->toContain('DATABASE_URL')
        ->and($job['env']['DATABASE_URL'])->toBe('postgres://secret@db/app');
});

test('app templates need a repository and commit, and game servers need the EULA accepted', function () {
    [$workspace, , $url] = templateMachine();
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson($url, ['template' => 'laravel', 'service' => 'shop'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repo', 'commit']);

    $this->actingAs($owner)->postJson($url, ['template' => 'minecraft-java', 'service' => 'survival'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['env.EULA']);

    $this->actingAs($owner)->postJson($url, ['template' => 'nope', 'service' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['template']);

    $this->actingAs($owner)->postJson($url, ['template' => 'node', 'service' => 'x', 'repo' => 'https://github.com/a/b.git', 'commit' => str_repeat('c', 40), 'extra_env' => 'not a pair'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['extra_env']);

    Http::assertNotSent(fn (GatewayRequest $request) => str_ends_with($request->url(), '/commands'));

    $this->actingAs($owner)->postJson($url, ['template' => 'minecraft-java', 'service' => 'survival', 'env' => ['EULA' => 'true']])
        ->assertAccepted();

    expect(sentJob()['env']['EULA'])->toBe('TRUE');
});

test('viewers cannot deploy templates', function () {
    [$workspace, , $url] = templateMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson($url, ['template' => 'redis', 'service' => 'cache'])
        ->assertForbidden();
});

test('without a domain a web service has no public URL, only its local address and a tunnel', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'umami', 'service' => 'stats'])
        ->assertAccepted()
        ->assertJson([
            'url' => null,
            'localUrl' => 'http://127.0.0.1:3000',
            'tunnel' => 'ssh -N -L 3000:127.0.0.1:3000 root@203.0.113.7',
        ]);
});

test('a machine without Docker is told how to install it instead of queueing a deploy that fails', function () {
    [$workspace, $machine, $url] = templateMachine();
    $machine->forceFill(['inventory' => ['services' => [['key' => 'systemd:nginx.service']]], 'facts' => ['os' => 'Ubuntu']])->save();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'redis', 'service' => 'cache'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.form.0', fn (string $message) => str_contains($message, 'get.docker.com'));

    $machine->forceFill(['facts' => ['os' => 'Ubuntu', 'docker_version' => '27.3.1']])->save();
    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'redis', 'service' => 'cache'])->assertAccepted();
});

test('a pasted URL in the domain box becomes its host', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'nextjs',
        'service' => 'web',
        'repo' => 'https://github.com/example/web.git',
        'commit' => str_repeat('e', 40),
        'domain' => 'https://Unemployment66.com/',
    ])->assertAccepted();

    expect(sentJob()['domains'])->toBe(['unemployment66.com']);
});

test('a template with a slow first start gives its health check longer', function () {
    [$workspace, , $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'open-webui',
        'service' => 'chat',
    ])->assertAccepted();

    expect(sentJob()['health'])->toMatchArray(['path' => '/health', 'retries' => 20]);
});

test('the deploy dialog learns which names and ports are taken, so a second database gets its own', function () {
    [$workspace, $machine, $url] = templateMachine();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'postgres', 'service' => 'postgres'])->assertAccepted();
    $machine->forceFill(['inventory' => [
        'services' => [
            ['key' => 'docker:abc', 'name' => 'serveros-postgres-postgres-1', 'details' => ['compose_project' => 'serveros-postgres']],
            ['key' => 'docker:def', 'name' => '/serveros-shop-0123456789ab', 'details' => []],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx'],
        ],
        'listeners' => [['address' => '0.0.0.0', 'port' => 80, 'proto' => 'tcp'], ['address' => '127.0.0.1', 'port' => 5432, 'proto' => 'tcp']],
    ]])->save();

    $this->actingAs($workspace->owner)->getJson($url.'/taken')
        ->assertOk()
        ->assertExactJson(['services' => ['postgres', 'shop'], 'ports' => [80, 5432]]);

    $this->actingAs(User::factory()->create())->getJson($url.'/taken')->assertForbidden();
});
