<?php

namespace App\Console\Commands;

use App\Models\DaemonRelease;
use Illuminate\Console\Command;

/**
 * Register a signed daemon release from the manifests `serveros-release
 * manifest` writes (one per architecture). Running it again for a version
 * updates it, which is also how a canary release is promoted: --channel=stable.
 *
 * php artisan daemon:release manifest-amd64.json manifest-arm64.json --channel=stable
 */
class RegisterDaemonRelease extends Command
{
    protected $signature = 'daemon:release {manifests* : manifest-<arch>.json files} {--channel= : stable or canary (defaults to the manifest\'s)}';

    protected $description = 'Register a signed daemon release so machines update to it';

    public function handle(): int
    {
        foreach ($this->argument('manifests') as $file) {
            $manifest = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (! is_array($manifest) || ! isset($manifest['version'], $manifest['sha256'], $manifest['signature'])) {
                $this->error("{$file} isn't a release manifest.");

                return self::FAILURE;
            }

            $arch = $manifest['arch'] ?? (preg_match('/-(amd64|arm64)\.json$/', $file, $m) ? $m[1] : null);
            $channel = $this->option('channel') ?: ($manifest['channel'] ?? 'stable');
            if (! in_array($arch, DaemonRelease::ARCHES, true) || ! in_array($channel, DaemonRelease::CHANNELS, true)) {
                $this->error("{$file}: couldn't tell the architecture (amd64 or arm64) or channel (stable or canary).");

                return self::FAILURE;
            }

            $release = DaemonRelease::query()->updateOrCreate(
                ['version' => $manifest['version'], 'arch' => $arch],
                [
                    'channel' => $channel,
                    'sha256' => $manifest['sha256'],
                    'signature' => $manifest['signature'],
                    'min_from' => $manifest['min_from'] ?? null,
                    'notes_url' => $manifest['notes_url'] ?? null,
                ],
            );

            $this->components->info("{$release->version} ({$arch}) is on the {$channel} channel, served from {$release->toCandidate()['url']}");
            if (! $release->binaryIsServed()) {
                $this->components->warn('Its binary isn’t in '.DaemonRelease::path($release->version).' yet, so machines will fail to download it until it is.');
            }
        }

        if (($stable = DaemonRelease::publishStableVersion()) !== null) {
            $this->components->info("New machines install {$stable}.");
        }

        return self::SUCCESS;
    }
}
