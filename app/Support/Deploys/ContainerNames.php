<?php

namespace App\Support\Deploys;

/**
 * Names for containers whose own name says nothing: the ones Pterodactyl
 * set up are called by their server's UUID (10279aa4-91dd-…). They're
 * named from the image they run instead, with the UUID's first block so
 * two of the same kind can be told apart: "Discord egg · 10279aa4". A
 * name someone gave the service in the panel still comes first.
 */
final class ContainerNames
{
    /** Pterodactyl's general-purpose images, where the tag says what runs. */
    private const GENERAL_IMAGES = ['yolks', 'games', 'installers', 'images'];

    /** How well-known names are written. */
    private const SPELLINGS = [
        'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgres' => 'PostgreSQL', 'postgresql' => 'PostgreSQL',
        'mongo' => 'MongoDB', 'mongodb' => 'MongoDB', 'redis' => 'Redis', 'nodejs' => 'Node.js', 'node' => 'Node.js',
        'python' => 'Python', 'java' => 'Java', 'golang' => 'Go', 'dotnet' => '.NET', 'bun' => 'Bun', 'deno' => 'Deno',
        'php' => 'PHP', 'nginx' => 'nginx', 'caddy' => 'Caddy',
    ];

    /**
     * The name to show for a container, or null when its own will do.
     *
     * @param  array<string, mixed>  $service  an inventory entry
     */
    public static function found(array $service): ?string
    {
        $name = ltrim((string) ($service['name'] ?? ''), '/');
        if (! str_starts_with((string) ($service['key'] ?? ''), 'docker:') || ! self::unnamed($name, $service)) {
            return null;
        }

        $what = self::fromImage((string) ($service['details']['image'] ?? ''), (string) ($service['kind'] ?? ''));
        $id = preg_match('/^([0-9a-f]{8})-/i', $name, $match) ? strtolower($match[1]) : null;

        return match (true) {
            $what !== null && $id !== null => "{$what} · {$id}",
            $what !== null => $what,
            default => null,
        };
    }

    /**
     * Named by a UUID, or set up by Pterodactyl (Wings' files mounted).
     *
     * @param  array<string, mixed>  $service
     */
    private static function unnamed(string $name, array $service): bool
    {
        $mounts = (string) ($service['details']['mounts'] ?? '');

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1
            || str_contains($mounts, '/run/wings/')
            || str_contains($mounts, '/var/lib/pterodactyl/');
    }

    /**
     * What an image runs, in words: 5140/discord-egg:latest → "Discord egg",
     * ghcr.io/pterodactyl/yolks:java_17 → "Java 17", and a Java game server
     * → "Minecraft".
     */
    public static function fromImage(string $image, string $kind = ''): ?string
    {
        $image = preg_replace('/@sha256:[0-9a-f]+$/i', '', trim($image)) ?? '';
        if ($image === '' || preg_match('/^sha256:/i', $image)) {
            return null;
        }

        $last = substr($image, (int) strrpos('/'.$image, '/'));
        [$repo, $tag] = array_pad(explode(':', $last, 2), 2, null);

        if ($kind === 'game_server' && preg_match('/^java/i', (string) $tag)) {
            return 'Minecraft';
        }
        $words = in_array(strtolower($repo), self::GENERAL_IMAGES, true) && $tag !== null && $tag !== 'latest'
            ? $tag
            : $repo;

        $words = explode(' ', strtolower(trim(preg_replace('/[-_.]+/', ' ', $words) ?? '')));
        if ($words === ['']) {
            return null;
        }
        $first = array_shift($words);

        return trim((self::SPELLINGS[$first] ?? ucfirst($first)).' '.implode(' ', $words));
    }
}
