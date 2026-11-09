<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Support\Str;

test('the PHP to build on comes from the project’s Laravel, else its php constraint', function (array $require, string $php) {
    expect(RepositoryDetector::phpFor(['require' => $require])[0])->toBe($php);
})->with([
    'Laravel 8 pins libraries that break on 8.2' => [['php' => '^7.3|^8.0', 'laravel/framework' => '^8.65'], '8.1'],
    'Laravel 6' => [['php' => '^7.2', 'laravel/framework' => '^6.0'], '7.4'],
    'Laravel 9' => [['laravel/framework' => '^9.19'], '8.2'],
    'Laravel 10' => [['laravel/framework' => '^10.10'], '8.3'],
    'Laravel 11 and newer: the newest' => [['php' => '^8.2', 'laravel/framework' => '^11.0'], '8.4'],
    'a plain PHP project on 7.4' => [['php' => '^7.4'], '7.4'],
    'a range up to 8.1' => [['php' => '>=7.4 <8.2'], '8.1'],
    'open-ended' => [['php' => '>=8.0'], '8.4'],
    'an or' => [['php' => '~7.4 || ~8.0'], '8.4'],
    'nothing pinned' => [[], '8.4'],
    // Forms that are easy to get wrong.
    'a wildcard' => [['php' => '8.1.*'], '8.1'],
    'an exact minor' => [['php' => '8.2'], '8.2'],
    'a tilde with a patch' => [['php' => '~8.1.0'], '8.1'],
    'at most' => [['php' => '<=8.2'], '8.2'],
    'a closed range' => [['php' => '>=7.4 <=8.1'], '8.1'],
    'a hyphen range' => [['php' => '8.1 - 8.2'], '8.2'],
    'more than' => [['php' => '>8.2'], '8.4'],
    'a comma' => [['php' => '>=7.4,<8.0'], '7.4'],
]);

test('an old Laravel app is detected with its PHP version and a health check it has', function () {
    $match = app(RepositoryDetector::class)->match(['composer.json', 'artisan'], [
        'composer.json' => json_encode(['require' => ['php' => '^7.3|^8.0', 'laravel/framework' => '^8.65']]),
    ]);

    expect($match)->toMatchArray(['template' => 'laravel', 'php' => '8.1', 'healthPath' => '/']);

    $new = app(RepositoryDetector::class)->match(['composer.json', 'artisan'], [
        'composer.json' => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0']]),
    ]);
    expect($new['php'])->toBe('8.4')->and($new)->not->toHaveKey('healthPath');
});

test('a PHP template builds on the PHP version asked for', function () {
    $template = app(ServiceTemplateCatalog::class)->find('laravel');
    $machine = Machine::factory()->create();
    $input = ['service' => 'shop', 'repo' => 'https://github.com/acme/shop.git', 'commit' => str_repeat('a', 40)];

    $default = (new TemplateDeployment($template, $machine, $input))->job()['files'];
    $old = (new TemplateDeployment($template, $machine, [...$input, 'php' => '8.1']))->job()['files'];

    expect($default->{'.serveros/Dockerfile'})->toContain('FROM serversideup/php:8.4-fpm-nginx')
        ->and($old->{'.serveros/Dockerfile'})->toContain('FROM serversideup/php:8.1-fpm-nginx')
        ->not->toContain('8.4-fpm-nginx')
        ->and(TemplateDeployment::usesPhp($template))->toBeTrue()
        ->and(TemplateDeployment::usesPhp(app(ServiceTemplateCatalog::class)->find('postgres')))->toBeFalse();
});

test('deploying a template again keeps the secrets it generated last time, unless new ones are given', function () {
    $template = app(ServiceTemplateCatalog::class)->find('postgres');
    $machine = Machine::factory()->create();
    // It's still on the machine: its data, and the password it took, are too.
    $running = ['services' => [['key' => 'docker:pg', 'name' => 'serveros-db-postgres-1', 'status' => 'running', 'details' => ['compose_project' => 'serveros-db']]]];
    $machine->forceFill(['inventory' => $running])->save();

    $first = new TemplateDeployment($template, $machine, ['service' => 'db']);
    $password = $first->env['POSTGRES_PASSWORD'];
    expect($password)->not->toBe('')
        ->and(collect($first->generated)->pluck('key'))->toContain('POSTGRES_PASSWORD')
        ->and($first->kept)->toBe([]);
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'db',
        'state' => 'failed', 'log' => [], 'command' => ['job' => $first->job()]]);

    // Postgres only took the first password when it made its data.
    $again = new TemplateDeployment($template, $machine, ['service' => 'db']);
    expect($again->env['POSTGRES_PASSWORD'])->toBe($password)
        ->and($again->kept)->toContain('POSTGRES_PASSWORD')
        ->and(collect($again->generated)->pluck('key'))->not->toContain('POSTGRES_PASSWORD');

    $chosen = new TemplateDeployment($template, $machine, ['service' => 'db', 'env' => ['POSTGRES_PASSWORD' => 'brand-new-one']]);
    expect($chosen->env['POSTGRES_PASSWORD'])->toBe('brand-new-one');

    // Removed from the machine (say its password leaked): it starts fresh.
    $machine->forceFill(['inventory' => ['services' => []]])->save();
    $fresh = new TemplateDeployment($template, $machine->fresh(), ['service' => 'db']);
    expect($fresh->env['POSTGRES_PASSWORD'])->not->toBe($password)
        ->and($fresh->kept)->toBe([]);
    $machine->forceFill(['inventory' => $running])->save();

    // Another service, or another machine, starts fresh.
    expect((new TemplateDeployment($template, $machine, ['service' => 'other']))->env['POSTGRES_PASSWORD'])->not->toBe($password)
        ->and((new TemplateDeployment($template, Machine::factory()->create(), ['service' => 'db']))->env['POSTGRES_PASSWORD'])->not->toBe($password);
});
