<?php

namespace App\Console\Dev;

use App\Models\DaemonEnrolment;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

use function Laravel\Prompts\select;

/**
 * Local only: fresh test servers as Docker containers with systemd and
 * Docker inside (docker/test-node), ready to connect through Connect Server
 * in the panel. Runs them the way docker/test-node/up.sh does, so servers
 * from either work here. Needs `php artisan dev:daemon` first.
 */
class DevNode extends Command
{
    protected $signature = 'dev:node
        {action=up : up, shell, proxy, down or list}
        {name=test-node : The server is the container serveros-<name>, with that hostname}
        {--connect : Connect it to a workspace straight away instead of through the panel}
        {--workspace= : With --connect, the slug of the workspace to connect it to}';

    protected $description = 'Start, open, list or remove fresh local test servers';

    private const IMAGE = 'serveros-test-node';

    /** Containers named serveros-* that aren't test servers. */
    private const NOT_NODES = ['serveros-gateway-local', 'serveros-daemon-test'];

    public function handle(): int
    {
        try {
            match ($this->argument('action')) {
                'up' => $this->up(),
                'shell' => $this->shell(),
                'proxy' => $this->proxy(),
                'down' => $this->down(),
                'list' => $this->list(),
                default => throw new RuntimeException('Use up, shell, proxy, down or list.'),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function up(): void
    {
        $name = $this->nodeName();
        $container = "serveros-{$name}";

        $this->docker(['info'], 'Docker is not running. Start Docker Desktop and try again.');
        if (Process::run(['docker', 'container', 'inspect', $container])->successful()) {
            throw new RuntimeException("{$container} already exists. Remove it with `php artisan dev:node down {$name}`.");
        }
        if (trim(Process::run(['docker', 'ps', '-q', '--filter', 'name=^serveros-gateway-local$'])->output()) === '') {
            throw new RuntimeException('The local gateway is not running, so the server could not connect. Run `php artisan dev:daemon` first.');
        }

        // Cached, so this only rebuilds when the Dockerfile or Herd's CA changed.
        DevDaemon::copyHerdCa();
        $this->components->info('Building the test server image.');
        $this->stream(['docker', 'build', '-q', '-t', self::IMAGE, 'docker/test-node'], 'The image build failed.');

        // One server at a time can take the port the panel links services through.
        $proxyPort = config('daemon.local_node_port');
        $publish = filled($proxyPort) && ! $this->portInUse((int) $proxyPort);

        $this->components->info("Starting {$container}.");
        $this->docker([
            'run', '-d', '--name', $container, '--hostname', $container,
            '--privileged', '--cgroupns=private',
            '-v', '/sys/fs/cgroup:/sys/fs/cgroup:rw',
            '-v', "{$container}-docker:/var/lib/docker",
            ...($publish ? ['-p', "{$proxyPort}:80"] : []),
            self::IMAGE,
        ], "Could not start {$container}.");

        $this->waitFor('systemd to boot', 60, fn () => in_array(
            trim(Process::run(['docker', 'exec', $container, 'systemctl', 'is-system-running'])->output()),
            ['running', 'degraded'],
            true,
        ));

        if ($this->option('connect')) {
            $this->connect($container);
            if ($publish) {
                $this->proxy();
            }

            return;
        }

        $this->components->info("{$container} is running: a fresh Ubuntu 24.04 server with systemd and Docker.");
        $this->line('  1. In ServerOS, open Machines and click Connect Server.');
        $this->line("  2. Run `php artisan dev:node shell {$name}` and paste the install command there.");
        if ($publish) {
            $this->line("  To open its services from this Mac, run `php artisan dev:node proxy {$name}`; they then load at http://<port>.localhost:{$proxyPort}.");
        } elseif (filled($proxyPort)) {
            $this->line("  Port {$proxyPort} is taken by another test server, so this one can't have local service links.");
        }
    }

    /**
     * The panel's Connect Server flow, done here: create a one-time token and
     * run the same install command inside the server.
     */
    private function connect(string $container): void
    {
        $workspace = $this->workspace();
        $token = bin2hex(random_bytes(32));
        $enrolment = DaemonEnrolment::query()->create([
            'workspace_id' => $workspace->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->components->info('Installing the daemon.');
        $install = sprintf(
            'set -o pipefail; curl -fsSL %s | bash -s -- --token=%s --panel=%s --releases=%s',
            escapeshellarg((string) config('daemon.install_url')),
            escapeshellarg($token),
            escapeshellarg((string) config('daemon.panel_url')),
            escapeshellarg((string) config('daemon.releases_url')),
        );
        $this->stream(['docker', 'exec', $container, 'bash', '-lc', $install], 'The daemon install failed.');

        $this->waitFor('the server to connect', 60, fn () => $enrolment->refresh()->machine_id !== null);
        $this->components->info("Connected to {$workspace->name}: {$workspace->url()}/machines");
    }

    private function shell(): void
    {
        $container = $this->runningContainer();

        Process::forever()->tty()->run(['docker', 'exec', '-it', $container, 'bash', '-l']);
    }

    /**
     * Caddy on the server so http://<port>.localhost:<DAEMON_LOCAL_NODE_PORT>
     * on this Mac reaches its services; see docker/daemon-local/local-proxy.sh.
     */
    private function proxy(): void
    {
        $container = $this->runningContainer();
        $proxyPort = config('daemon.local_node_port');
        if (blank($proxyPort) || trim(Process::run(['docker', 'port', $container, '80'])->output()) === '') {
            throw new RuntimeException("{$container} doesn't publish port 80, so its services can't be reached from this Mac.");
        }

        $this->components->info('Installing the local proxy.');
        $this->stream([base_path('docker/daemon-local/local-proxy.sh'), $container], 'The local proxy install failed.');
        $this->line("  Services on it open at http://<port>.localhost:{$proxyPort} from this Mac.");
    }

    private function down(): void
    {
        $container = 'serveros-'.$this->nodeName();
        if (Process::run(['docker', 'container', 'inspect', $container])->failed()) {
            throw new RuntimeException("There is no {$container} container.");
        }

        $machine = $this->machine($container);
        $workspace = $machine === null ? null : Workspace::query()->whereKey($machine->workspace_id)->value('name');
        if ($machine !== null) {
            DaemonEnrolment::query()->where('machine_id', $machine->id)->delete();
            $machine->delete();
        }

        $this->docker(['rm', '-f', '-v', $container], "Could not remove {$container}.");
        Process::run(['docker', 'volume', 'rm', "{$container}-docker"]);
        $this->components->info($machine === null
            ? "Removed {$container}. It wasn't connected to a workspace."
            : "Removed {$container} and its machine in {$workspace}.");
    }

    private function list(): void
    {
        $result = Process::run(['docker', 'ps', '-a', '--filter', 'name=^serveros-', '--format', '{{.Names}}|{{.Status}}']);

        $rows = collect(explode("\n", trim($result->output())))
            ->filter()
            ->reject(fn (string $line): bool => in_array(explode('|', $line)[0], self::NOT_NODES, true))
            ->map(function (string $line): array {
                [$container, $status] = explode('|', $line) + ['', ''];
                $workspaceId = $this->machine($container)?->workspace_id;
                $slug = $workspaceId === null ? null : Workspace::query()->whereKey($workspaceId)->value('slug');

                return [$container, $status, $slug ?? 'not connected'];
            });

        if ($rows->isEmpty()) {
            $this->components->info('No test servers. Start one with `php artisan dev:node`.');

            return;
        }

        $this->table(['Container', 'Status', 'Workspace'], $rows->all());
    }

    /**
     * The panel's record of a connected server, found by the machine ID the
     * daemon keeps in its config, however the server was connected.
     */
    private function machine(string $container): ?Machine
    {
        $config = Process::run(['docker', 'exec', $container, 'cat', '/etc/serveros/daemon.toml']);
        if ($config->failed() || preg_match('/^id\s*=\s*"([0-9a-f-]{36})"/m', $config->output(), $match) !== 1) {
            return null;
        }

        return Machine::query()->where('uid', $match[1])->first();
    }

    private function runningContainer(): string
    {
        $container = 'serveros-'.$this->nodeName();
        if (trim(Process::run(['docker', 'ps', '-q', '--filter', "name=^{$container}\$"])->output()) === '') {
            throw new RuntimeException("{$container} isn't running. Start one with `php artisan dev:node up {$this->argument('name')}`.");
        }

        return $container;
    }

    private function nodeName(): string
    {
        $name = (string) $this->argument('name');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $name) !== 1) {
            throw new RuntimeException('Use lowercase letters, numbers and dashes for the name.');
        }

        return $name;
    }

    private function workspace(): Workspace
    {
        if (filled($slug = $this->option('workspace'))) {
            return Workspace::query()->where('slug', $slug)->first()
                ?? throw new RuntimeException("No workspace with the slug {$slug}.");
        }

        $workspaces = Workspace::query()->orderBy('name')->pluck('name', 'slug');

        return match ($workspaces->count()) {
            0 => throw new RuntimeException('There are no workspaces yet. Register in the panel first.'),
            1 => Workspace::query()->firstOrFail(),
            default => Workspace::query()->where('slug', select('Which workspace should it join?', $workspaces->all()))->firstOrFail(),
        };
    }

    private function waitFor(string $what, int $seconds, callable $check): void
    {
        $deadline = time() + $seconds;
        while (! $check()) {
            if (time() >= $deadline) {
                throw new RuntimeException("Timed out waiting for {$what}.");
            }
            usleep(500_000);
        }
    }

    private function portInUse(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** @param  list<string>  $command */
    private function docker(array $command, string $error): void
    {
        $result = Process::run(['docker', ...$command]);
        if ($result->failed()) {
            throw new RuntimeException($error.' '.trim($result->errorOutput()));
        }
    }

    /** @param  list<string>  $command */
    private function stream(array $command, string $error): void
    {
        $result = Process::forever()
            ->path(base_path())
            ->run($command, fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            throw new RuntimeException($error);
        }
    }
}
