<?php

namespace App\Support\Files;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use Illuminate\Support\Facades\Crypt;

/**
 * The Files tab's daemon jobs: list a folder, read, write or delete a file.
 * The daemon only reaches its own app folders and the working and data
 * folders of services it manages, and refuses anything else itself.
 *
 * File contents pass through the panel but aren't kept: a read is sealed
 * as it arrives and forgotten once the browser has it, and a write's
 * content is dropped from the job once the daemon is done with it.
 */
final class FileJobs
{
    /** Each operation: the panel's job kind, and the daemon job it sends. */
    public const array OPS = [
        'list' => ['file_list', 'list_dir'],
        'read' => ['file_read', 'read_file'],
        'write' => ['file_write', 'write_file'],
        'delete' => ['file_delete', 'delete_file'],
    ];

    public const array KINDS = ['file_list', 'file_read', 'file_write', 'file_delete'];

    /** Where the daemon keeps what it needs for apps deployed from code. */
    public const string APPS_DIR = '/var/lib/serveros/apps';

    /**
     * Biggest file the panel writes in one go. The gateway takes commands
     * up to 1 MB, and base64 adds a third.
     */
    public const int MAX_WRITE_BYTES = 512 * 1024;

    /** Biggest file the panel asks for; it comes back through the gateway. */
    public const int MAX_READ_BYTES = 2 * 1024 * 1024;

    /**
     * A finished job's result as it's kept: a read's content encrypted, so
     * it never sits in the database in the clear.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function seal(DaemonJob $job, array $result): array
    {
        if ($job->kind !== 'file_read' || ! is_string($result['content_b64'] ?? null)) {
            return $result;
        }

        return ['size' => (int) ($result['size'] ?? 0), 'sealed' => Crypt::encryptString($result['content_b64'])];
    }

    /** Drop a finished write's content from the kept command. */
    public static function forgetContent(DaemonJob $job): void
    {
        $command = $job->command;
        if ($job->kind !== 'file_write' || ! isset($command['job']['content_b64'])) {
            return;
        }

        unset($command['job']['content_b64']);
        $job->forceFill(['command' => $command])->save();
    }

    /**
     * The result for the browser. A read's content is handed over once,
     * then forgotten.
     *
     * @return array<string, mixed>|null
     */
    public static function deliver(DaemonJob $job): ?array
    {
        $result = $job->result;
        if ($job->kind !== 'file_read' || ! is_string($result['sealed'] ?? null)) {
            return $result;
        }

        $job->forceFill(['result' => ['size' => $result['size'] ?? null, 'delivered' => true]])->save();

        return ['size' => $result['size'] ?? null, 'content_b64' => Crypt::decryptString($result['sealed'])];
    }

    /**
     * Folders worth starting from on a machine: one or two per service
     * the daemon lets ServerOS into, by name.
     *
     * @return list<array{label: string, path: string}>
     */
    public static function places(Machine $machine): array
    {
        $places = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (! is_array($service)) {
                continue;
            }
            foreach (self::placesFor($machine, $service) as $place) {
                $places[$place['path']] ??= $place;
            }
        }
        usort($places, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $places;
    }

    /** Where the daemon keeps a deploy's files (compose.yaml, the env file). */
    public const string JOBS_DIR = '/var/lib/serveros/jobs';

    /**
     * System locations never offered as places, matching the daemon's
     * mountable_root(): a container mounting one doesn't open it.
     */
    private const array SYSTEM_ROOTS = [
        '/etc', '/proc', '/sys', '/dev', '/run', '/var/run', '/boot', '/root', '/bin', '/sbin',
        '/lib', '/lib64', '/usr', '/var/lib/docker/containers',
    ];

    /**
     * Where a service's files are, as far as the daemon lets ServerOS in:
     * an app deployed from code has its folder of settings; a template
     * ServerOS deployed, its folder of compose files; an adopted service, its
     * working folder and data folder; and any container ServerOS manages,
     * the folders mounted into it. Services ServerOS only discovered have
     * none.
     *
     * @param  array<string, mixed>  $service  an inventory entry
     * @return list<array{label: string, path: string}>
     */
    public static function placesFor(Machine $machine, array $service): array
    {
        $release = AppReleases::parse($service['name'] ?? null);
        if ($release !== null && isset(AppReleases::live($machine)[$release['app']])) {
            return [['label' => $release['app'], 'path' => self::APPS_DIR.'/'.self::folderName($release['app'])]];
        }
        if (($service['details']['managed'] ?? null) !== 'true') {
            return [];
        }

        $name = ServiceLabels::display($machine, $service);
        $created = ($service['origin'] ?? null) === 'created';
        $places = [];
        $add = function (string $label, mixed $path) use (&$places): void {
            if (is_string($path) && self::openable($path) && ! in_array(rtrim($path, '/'), array_column($places, 'path'), true)) {
                $places[] = ['label' => $label, 'path' => rtrim($path, '/')];
            }
        };

        if ($created) {
            // A template's compose files, kept by the daemon.
            if (is_string($service['working_dir'] ?? null) && str_starts_with($service['working_dir'], self::JOBS_DIR.'/')) {
                $add("{$name} · compose files", $service['working_dir']);
            }
        } else {
            $add($name, $service['working_dir'] ?? null);
            $add("{$name} · data", $service['data_dir'] ?? null);
        }

        // Where a container's files actually live: the folders mounted into it.
        if (str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
            foreach (explode(', ', (string) ($service['details']['mounts'] ?? '')) as $mount) {
                $at = strrpos($mount, ':');
                if ($at !== false) {
                    $add("{$name} · ".substr($mount, $at + 1), substr($mount, 0, $at));
                }
            }
        }

        return $places;
    }

    /** An absolute folder that isn't the root or a system location, nor a parent of one. */
    private static function openable(string $path): bool
    {
        $path = rtrim($path, '/');
        // An empty path (the root, once trimmed) doesn't start with a slash either.
        if (! str_starts_with($path, '/') || str_contains($path, '/..')) {
            return false;
        }
        foreach (self::SYSTEM_ROOTS as $system) {
            if ($path === $system || str_starts_with($path, $system.'/') || str_starts_with($system, $path.'/')) {
                return false;
            }
        }

        return true;
    }

    /** The daemon's folder name for an app: anything but [A-Za-z0-9_-] becomes "-". */
    private static function folderName(string $app): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $app);
    }
}
