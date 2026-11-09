<?php

namespace App\Console\Commands;

use App\Models\DaemonRelease;
use App\Support\DaemonUpdates;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * A self-hosted panel's daemon releases, copied from serveros.com's feed
 * (config daemon.release_feed_url): each release is registered there only,
 * and a daemon asks its own panel what to update to. The binaries stay on
 * releases.serveros.com, and each daemon checks a release's signature
 * against the key built into it before installing, so the feed can't make
 * a machine run anything ServerOS didn't sign. Scheduled hourly on
 * self-hosted installs only (routes/console.php).
 */
class SyncDaemonReleases extends Command
{
    protected $signature = 'daemon:sync-releases';

    protected $description = 'Copy the daemon releases serveros.com offers, so a self-hosted panel\'s machines update too';

    public function handle(DaemonUpdates $updates): int
    {
        $feed = (string) config('daemon.release_feed_url');
        if (! filter_var($feed, FILTER_VALIDATE_URL)) {
            $this->error('Set DAEMON_RELEASE_FEED_URL to the feed to copy releases from.');

            return self::FAILURE;
        }

        $new = [];
        foreach (DaemonRelease::CHANNELS as $channel) {
            foreach (DaemonRelease::ARCHES as $arch) {
                try {
                    $response = Http::timeout(15)->acceptJson()->get($feed, ['channel' => $channel, 'arch' => $arch]);
                } catch (ConnectionException $e) {
                    $this->warn("Couldn't reach {$feed}: {$e->getMessage()}");

                    return self::FAILURE;
                }
                // Nothing released on that channel for it yet.
                if ($response->status() === 404) {
                    continue;
                }
                $data = $response->successful() ? $response->json() : null;
                $validator = Validator::make(is_array($data) ? $data : [], [
                    'version' => ['required', 'string', 'max:40', 'regex:/^v?\d+\.\d+\.\d+([-+][0-9A-Za-z.\-+]+)?$/'],
                    'channel' => ['required', 'in:'.implode(',', DaemonRelease::CHANNELS)],
                    'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/i'],
                    'signature' => ['required', 'string', 'max:200'],
                    'min_from' => ['nullable', 'string', 'max:40'],
                    'notes_url' => ['nullable', 'url', 'max:255'],
                ]);
                if ($validator->fails()) {
                    $this->warn("{$feed} answered {$response->status()} for {$channel} {$arch}, not a release.");

                    continue;
                }

                $release = DaemonRelease::query()->updateOrCreate(
                    ['version' => $validator->validated()['version'], 'arch' => $arch],
                    [...$validator->validated(), 'arch' => $arch],
                );
                if ($release->wasRecentlyCreated) {
                    $this->info("{$release->version} ({$release->channel}, {$arch}) is new.");
                    if ($release->channel === 'stable') {
                        $new[$arch] = true;
                    }
                }
            }
        }

        // Out now, not at each daemon's next check.
        foreach (array_keys($new) as $arch) {
            $updates->push($arch);
        }

        return self::SUCCESS;
    }
}
