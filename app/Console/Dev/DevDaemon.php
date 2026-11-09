<?php

namespace App\Console\Dev;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Local only: build the daemon from its repository, configure this
 * panel for it, and start the gateway, using the scripts in docker/daemon-local.
 */
class DevDaemon extends Command
{
    protected $signature = 'dev:daemon {--rebuild : Rebuild the daemon even when a local release exists}';

    protected $description = 'Build the local daemon release, configure the panel and start the gateway in Docker';

    public function handle(): int
    {
        if (! Process::run(['docker', 'info'])->successful()) {
            $this->error('Docker is not running. Start Docker Desktop and try again.');

            return self::FAILURE;
        }

        try {
            self::copyHerdCa();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $built = file_exists(public_path('releases/daemon/stable/VERSION'))
            && is_executable(storage_path('app/private/gateway/serveros-gateway'));

        if ($built && ! $this->option('rebuild')) {
            $this->components->info('Using the existing local daemon release. Pass --rebuild to build it again.');
        } elseif (! $this->script('build.sh', 'Building the daemon (the first build takes several minutes)')) {
            return self::FAILURE;
        }

        if (! $this->script('setup.sh', 'Configuring the panel') || ! $this->script('start-gateway.sh', 'Starting the gateway')) {
            return self::FAILURE;
        }

        $this->components->info('Ready. Run `php artisan dev:node` to start a test node.');

        return self::SUCCESS;
    }

    /**
     * Copy this Mac's Herd CA, which signs serveros.test, to where the test
     * machines and the gateway trust it. Every Herd install has its own CA.
     */
    public static function copyHerdCa(): void
    {
        $source = getenv('HOME').'/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem';
        if (! is_readable($source)) {
            throw new RuntimeException("Herd's CA was not found at {$source}. Is Herd installed and serving serveros.test over HTTPS?");
        }

        $target = base_path('docker/test-node/herd-ca.pem');
        if (! file_exists($target) || sha1_file($target) !== sha1_file($source)) {
            copy($source, $target);
        }
    }

    private function script(string $name, string $title): bool
    {
        $this->components->info($title.'.');

        $result = Process::forever()
            ->path(base_path())
            ->run([base_path("docker/daemon-local/{$name}")], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->error("docker/daemon-local/{$name} failed.");
        }

        return $result->successful();
    }
}
