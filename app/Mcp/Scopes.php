<?php

namespace App\Mcp;

/**
 * What a connection may do, on top of the person's own role. Read is
 * always granted; changes and container commands are asked for and shown
 * separately, so a connection gets no more than it needs.
 */
final class Scopes
{
    public const READ = 'workspace:read';

    public const WRITE = 'workspace:write';

    public const COMMANDS = 'workspace:commands';

    public const ALL = [self::READ, self::WRITE, self::COMMANDS];

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function describe(): array
    {
        return [
            self::READ => ['label' => 'See your workspace', 'description' => 'Machines, services, monitors, status pages, workflows, backups, logs and activity.'],
            self::WRITE => ['label' => 'Make changes', 'description' => 'Deploy, restart, rename, organise, and change settings, as far as your role allows.'],
            self::COMMANDS => ['label' => 'Run commands', 'description' => 'Run shell commands inside your containers. Needs two-factor authentication.'],
        ];
    }

    /**
     * The scopes asked for, made sensible: known ones only, read always
     * included, commands only with changes.
     *
     * @param  list<string>|string|null  $requested
     * @return list<string>
     */
    public static function normalise(array|string|null $requested): array
    {
        $list = is_array($requested) ? $requested : (preg_split('/\s+/', trim((string) $requested)) ?: []);
        $scopes = array_values(array_intersect(self::ALL, array_unique([self::READ, ...$list])));
        if (in_array(self::COMMANDS, $scopes, true) && ! in_array(self::WRITE, $scopes, true)) {
            $scopes = array_values(array_diff($scopes, [self::COMMANDS]));
        }

        return $scopes;
    }
}
