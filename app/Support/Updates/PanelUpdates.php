<?php

namespace App\Support\Updates;

use App\Models\Activity;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonUpdates;
use App\Support\InstallPing;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PanelUpdates
{
    public const LATEST = 'panel-updates:latest';

    public const UPDATER_SEEN = 'panel-updates:updater-seen';

    private const STALE_MINUTES = 30;

    public function __construct(private readonly DaemonUpdates $daemons) {}

    public static function current(): string
    {
        return InstallPing::version();
    }

    /** @return array{release: array{version: string, url: string|null, publishedAt: string|null}|null, checkedAt: int, reached: bool} */
    public static function check(): array
    {
        $check = ['release' => self::fetch(), 'checkedAt' => now()->getTimestamp(), 'reached' => true];
        if ($check['release'] === false) {
            $check = ['release' => self::lastCheck()['release'] ?? null, 'checkedAt' => $check['checkedAt'], 'reached' => false];
        }
        Cache::forever(self::LATEST, $check);

        return $check;
    }

    /** @return array{release: array{version: string, url: string|null, publishedAt: string|null}|null, checkedAt: int, reached: bool}|null */
    public static function lastCheck(): ?array
    {
        $check = Cache::get(self::LATEST);
        if (! is_array($check) || ! is_int($check['checkedAt'] ?? null)) {
            return null;
        }
        $release = $check['release'] ?? null;

        return [
            'release' => is_array($release) && is_string($release['version'] ?? null) ? [
                'version' => $release['version'],
                'url' => is_string($release['url'] ?? null) ? $release['url'] : null,
                'publishedAt' => is_string($release['publishedAt'] ?? null) ? $release['publishedAt'] : null,
            ] : null,
            'checkedAt' => $check['checkedAt'],
            'reached' => ($check['reached'] ?? true) === true,
        ];
    }

    /** @return array{version: string, url: string|null, publishedAt: string|null}|null */
    public static function available(): ?array
    {
        $current = self::current();
        $release = self::lastCheck()['release'] ?? null;

        return $current !== 'dev' && $release !== null && DaemonUpdates::newer($release['version'], $current) ? $release : null;
    }

    /** @return array{version: string, current: string}|null */
    public static function notice(): ?array
    {
        $release = self::available();
        if ($release === null || PanelUpdate::query()->whereIn('status', [PanelUpdate::REQUESTED, PanelUpdate::RUNNING])->exists()) {
            return null;
        }

        return ['version' => $release['version'], 'current' => self::current()];
    }

    public static function updaterSeen(): bool
    {
        $seen = Cache::get(self::UPDATER_SEEN);

        return is_numeric($seen) && (int) $seen >= now()->subMinutes(5)->getTimestamp();
    }

    /** @throws RuntimeException */
    public static function request(Workspace $workspace, User $user): PanelUpdate
    {
        self::expireStale();
        if (PanelUpdate::query()->whereIn('status', [PanelUpdate::REQUESTED, PanelUpdate::RUNNING])->exists()) {
            throw new RuntimeException(__('An update is already on its way.'));
        }
        if (! (bool) config('serveros.install.docker') || ! self::updaterSeen()) {
            throw new RuntimeException(__('The server isn’t running the updater, so this install can’t update from here yet.'));
        }
        $release = self::available() ?? throw new RuntimeException(__('This install already runs the newest ServerOS.'));

        return PanelUpdate::query()->create([
            'version' => $release['version'],
            'from_version' => self::current(),
            'workspace_id' => $workspace->id,
            'requested_by' => $user->id,
        ]);
    }

    public static function claim(): ?PanelUpdate
    {
        Cache::forever(self::UPDATER_SEEN, now()->getTimestamp());
        self::expireStale();

        $update = PanelUpdate::query()->where('status', PanelUpdate::REQUESTED)->oldest('id')->first();
        if ($update === null) {
            return null;
        }
        $claimed = PanelUpdate::query()->whereKey($update->id)->where('status', PanelUpdate::REQUESTED)
            ->update(['status' => PanelUpdate::RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        return $claimed === 1 ? $update->refresh() : null;
    }

    public function finish(PanelUpdate $update): int
    {
        $running = self::current();
        if ($running === 'dev' || DaemonUpdates::newer($update->version, $running)) {
            self::failed($update, __('The panel came back on :running, not :version. The image for :version may not be out yet, so try again in a few minutes.', ['running' => $running, 'version' => $update->version]));

            return 0;
        }

        $update->update(['status' => PanelUpdate::DONE, 'error' => null, 'finished_at' => now()]);
        $workspace = $update->workspace;
        if ($workspace === null) {
            return 0;
        }

        Artisan::call('daemon:sync-releases');
        $sent = 0;
        foreach ($workspace->machines()->whereNotNull('uid')->whereNull('disconnected_at')->get() as $machine) {
            if (! $machine->isOnline() || MachinePolicies::for($machine)->updates === 'automatic' || DaemonUpdates::available($machine) === null) {
                continue;
            }
            try {
                $from = $machine->daemon_version;
                $release = $this->daemons->install($machine);
                Activity::record($workspace, 'machine.daemon_update', $machine->name, $machine, $update->requester, ['from' => $from, 'to' => $release->version]);
                $sent++;
            } catch (RuntimeException) {
                continue;
            }
        }
        Activity::system($workspace, 'panel.updated', 'ServerOS', meta: ['from' => $update->from_version, 'to' => $running, 'machines' => $sent]);

        return $sent;
    }

    public static function failed(PanelUpdate $update, string $error): void
    {
        $update->fail($error);
        if ($update->workspace_id !== null) {
            Activity::system($update->workspace_id, 'panel.update_failed', 'ServerOS', meta: ['version' => $update->version, 'error' => $update->error]);
        }
    }

    public static function expireStale(): void
    {
        $stale = now()->subMinutes(self::STALE_MINUTES);
        PanelUpdate::query()->where('status', PanelUpdate::REQUESTED)->where('created_at', '<', $stale)->get()
            ->each(fn (PanelUpdate $update) => self::failed($update, __('The server didn’t pick the update up. Check the updater runs there with serveros-panel schedule.')));
        PanelUpdate::query()->where('status', PanelUpdate::RUNNING)->where('started_at', '<', $stale)->get()
            ->each(fn (PanelUpdate $update) => self::failed($update, __('The update didn’t finish. See /var/log/serveros-panel-update.log on the server.')));
    }

    /** @return array<string, mixed> */
    public static function props(): array
    {
        self::expireStale();
        $check = self::lastCheck();
        $last = PanelUpdate::query()->with('requester')->latest('id')->first();

        return [
            'current' => self::current(),
            'docker' => (bool) config('serveros.install.docker'),
            'latest' => $check['release'] ?? null,
            'available' => self::available() !== null,
            'checkedAt' => isset($check['checkedAt']) ? now()->setTimestamp($check['checkedAt'])->toIso8601String() : null,
            'reached' => $check['reached'] ?? true,
            'updater' => self::updaterSeen(),
            'update' => $last === null ? null : [
                'id' => $last->id,
                'version' => $last->version,
                'from' => $last->from_version,
                'status' => $last->status,
                'error' => $last->error,
                'by' => $last->requester?->name,
                'requestedAt' => $last->created_at->toIso8601String(),
                'finishedAt' => $last->finished_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array{version: string, url: string|null, publishedAt: string|null}|null|false */
    private static function fetch(): array|false|null
    {
        $feed = (string) config('serveros.updates.feed_url');
        if (! filter_var($feed, FILTER_VALIDATE_URL)) {
            return null;
        }
        try {
            $response = Http::timeout(10)->connectTimeout(5)->acceptJson()
                ->withUserAgent('ServerOS/'.self::current())
                ->get($feed);
        } catch (ConnectionException) {
            return false;
        }
        if (! $response->successful()) {
            return $response->status() === 404 ? null : false;
        }

        $tag = $response->json('tag_name');
        if (! is_string($tag) || preg_match('/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $tag) !== 1 || $response->json('draft') === true || $response->json('prerelease') === true) {
            return null;
        }
        $url = $response->json('html_url');
        $published = $response->json('published_at');

        return [
            'version' => ltrim($tag, 'v'),
            'url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
            'publishedAt' => is_string($published) ? $published : null,
        ];
    }
}
