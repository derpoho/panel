<?php

use App\Support\Deploys\DeployFailure;

/** The cause the explainer gives for a failure, or null. */
function causeOf(string $phase, string $message, array $tail = [], bool $timedOut = false): ?string
{
    return DeployFailure::fromError($phase, $message, $tail, $timedOut)['cause'] ?? null;
}

// Real failures, from real deploys.

test('a snapshot of a stopped container isn’t blamed on disk space', function () {
    $why = DeployFailure::fromError('dump', 'docker exited 1', [
        'Error response from daemon: container efa4977e533b9227c68f06c06592551638b82af7fb4136854d25117edbabc7a2 is not running',
    ]);

    expect($why['cause'])->toContain('container isn’t running')
        ->and($why['fix'])->toContain('Snapshot data before each deploy');
});

test('a template bound to an address the machine doesn’t have says so, not "fix the build"', function () {
    expect(causeOf('build', 'docker compose up exited 1', [
        'Container serveros-pgtest-postgres-1 Starting',
        'Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint serveros-pgtest-postgres-1 (9cbb1457f52a): failed to bind host port 172.17.0.1:5432/tcp: cannot assign requested address',
    ]))->toContain('address this machine doesn’t have');
});

test('a stopped Caddy says to start it, not just "check systemctl"', function () {
    $why = DeployFailure::fromError('proxy', 'caddy reload failed', [
        '{"level":"warn","msg":"Caddyfile input is not formatted; run \'caddy fmt --overwrite\' to fix inconsistencies"}',
        'Error: sending configuration to instance: performing request: Post "http://localhost:2019/load": dial tcp [::1]:2019: connect: connection refused',
    ]);

    expect($why['cause'])->toContain('Caddy')
        ->and($why['command'])->toBe('systemctl enable --now caddy');
});

// The common causes, from the lines their tools print.

test('each common cause is recognised from its output', function (string $phase, string $message, array $tail, string $expected) {
    expect(causeOf($phase, $message, $tail))->toContain($expected);
})->with([
    'no docker' => ['build', 'Failed to find executable docker', [], 'Docker isn’t installed'],
    'no compose plugin' => ['build', 'docker compose up exited 1', ["docker: 'compose' is not a docker command."], 'without its build and Compose plugins'],
    'disk' => ['build', 'docker build failed at Step 7, exited 1', ['ERROR: failed to solve: write /var/lib/docker/tmp/x: no space left on device'], 'ran out of disk space'],
    'node heap' => ['build', 'docker build exited 1', ['FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory'], 'ran out of memory'],
    'killed' => ['build', 'docker build exited 137', ['#12 41.3 Killed'], 'ran out of memory'],
    'private repo' => ['fetch', 'git fetch exited 128', ["fatal: could not read Username for 'https://github.com': terminal prompts disabled"], 'private, or the address is wrong'],
    'dns' => ['build', 'docker build exited 1', ['npm ERR! getaddrinfo EAI_AGAIN registry.npmjs.org', 'curl: (6) Could not resolve host: deb.debian.org'], 'couldn’t reach the internet'],
    'hub limit' => ['build', 'docker build exited 1', ['ERROR: toomanyrequests: You have reached your unauthenticated pull rate limit.'], 'download limit'],
    'bad image' => ['build', 'docker build exited 1', ['ERROR: failed to solve: node:99-alpine: docker.io/library/node:99-alpine: not found', 'manifest for node:99-alpine not found: manifest unknown'], 'name or tag is wrong'],
    'port taken' => ['build', 'docker compose up exited 1', ['Error response from daemon: driver failed programming external connectivity: Bind for 127.0.0.1:3000 failed: port is already allocated'], 'already taken'],
    'no build script' => ['build', 'docker build failed at Step 6, exited 1', ['npm ERR! Missing script: "build"'], 'no build script'],
    'lockfile' => ['build', 'docker build exited 1', ['npm ERR! `npm ci` can only install packages when your package.json and package-lock.json or npm-shrinkwrap.json are in sync.'], 'package-lock.json'],
    'node version' => ['build', 'docker build exited 1', ['error my-app@1.0.0: The engine "node" is incompatible with this module. Expected version ">=20". Got "18.19.0"'], 'version of Node.js'],
    'php version' => ['build', 'docker build exited 2', ['  Problem 1', '    - Root composer.json requires php ^8.3 but your php version (8.2.12) does not satisfy that requirement.'], 'version of PHP'],
    'composer' => ['build', 'docker build exited 2', ['Your lock file does not contain a compatible set of packages. Please run composer update.'], 'Composer couldn’t install'],
    'pip' => ['build', 'docker build exited 1', ['ERROR: Could not find a version that satisfies the requirement django==9.0', 'ERROR: No matching distribution found for django==9.0'], 'pip couldn’t find'],
    'missing file' => ['build', 'docker build exited 1', ['ERROR: failed to solve: failed to compute cache key: failed to calculate checksum of ref abc::xyz: "/dist": not found'], 'isn’t in the repository'],
    'env var' => ['health', 'release never became healthy: /health returned HTTP 500, expected 200', ['Error: Missing required environment variable DATABASE_URL'], 'DATABASE_URL'],
    'laravel key' => ['health', 'release never became healthy: / returned HTTP 500, expected 200', ['No application encryption key has been specified.'], 'APP_KEY'],
    'not listening' => ['health', 'release never became healthy: port 3000 not accepting connections (Connection refused (os error 111))', [], 'nothing answered on its port'],
    'answers 500' => ['health', 'release never became healthy: /health returned HTTP 502, expected 200', [], 'answers with an error'],
    'never healthy' => ['health', 'release never became healthy: no response within 5s', [], 'never answered its health check'],
]);

test('a timeout says so', function () {
    expect(causeOf('build', 'docker build timed out', [], timedOut: true))->toContain('took too long');
});

test('ordinary log lines about environment variables aren’t mistaken for a missing one', function () {
    expect(causeOf('build', 'docker build failed at Step 9, exited 2', [
        'Step 4/9 : ENV NODE_ENV production',
        'Reading environment variable PATH from the image',
        'error TS2304: Cannot find name \'foo\'.',
    ]))->toBeNull();
});

test('something it doesn’t recognise keeps the daemon’s words', function () {
    expect(DeployFailure::fromError('build', 'docker build failed at Step 9, exited 2', ['src/index.ts(3,1): error TS2304: Cannot find name \'foo\'.']))->toBeNull();
});

test('missing Docker or its plugins offers to install them', function () {
    expect(DeployFailure::fromError('build', 'Failed to find executable docker', [])['install'])
        ->toBe(['label' => 'Install Docker', 'packages' => DeployFailure::DOCKER_PACKAGES])
        ->and(DeployFailure::fromError('build', 'docker compose up exited 1', ["docker: 'compose' is not a docker command."])['install']['packages'])
        ->toBe(['docker-buildx', 'docker-compose-v2'])
        ->and(DeployFailure::fromError('build', 'docker build exited 1', ['no space left on device']))->not->toHaveKey('install');
});
