<?php

namespace App\Support\Projects;

use App\Models\DaemonJob;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;

/**
 * What an app needs to use a resource: the settings it reads, filled in
 * from the resource's own deploy (its user, password, port) and the
 * address it's reached at. One recipe per template a project can add.
 *
 * A deployed resource is recognised by its release name: a template-only
 * deploy is named by a digest of the template (TemplateDeployment::commit),
 * so it matches exactly one template.
 */
final class Connections
{
    /**
     * Each template: what kind of resource it is, and whether apps on its
     * own machine can reach it through the Docker bridge (MinIO only
     * listens on 127.0.0.1).
     *
     * @var array<string, array{kind: string, bridge: bool}>
     */
    public const array TEMPLATES = [
        'postgres' => ['kind' => 'database', 'bridge' => true],
        'mysql' => ['kind' => 'database', 'bridge' => true],
        'mariadb' => ['kind' => 'database', 'bridge' => true],
        'mongodb' => ['kind' => 'database', 'bridge' => true],
        'redis' => ['kind' => 'cache', 'bridge' => true],
        'valkey' => ['kind' => 'cache', 'bridge' => true],
        'minio' => ['kind' => 'storage', 'bridge' => false],
        'soketi' => ['kind' => 'websockets', 'bridge' => true],
    ];

    /** What the add menu installs for each kind. */
    public const array DEFAULTS = [
        'database' => 'postgres',
        'cache' => 'valkey',
        'storage' => 'minio',
        'websockets' => 'soketi',
    ];

    public function __construct(private readonly ServiceTemplateCatalog $catalog) {}

    /** The template a deploy came from, if it's one a project knows. */
    public function templateOf(DaemonJob $deploy): ?string
    {
        $commit = (string) ($deploy->command['job']['commit'] ?? '');
        foreach (array_keys(self::TEMPLATES) as $slug) {
            $template = $this->catalog->find($slug);
            if ($template !== null && hash_equals(sha1($slug."\n".json_encode($template->files)), $commit)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * The settings an app gets to use this resource at this address.
     *
     * @param  array<string, mixed>  $spec  the resource's deploy job
     * @return array<string, string>
     */
    public function env(string $template, array $spec, string $host): array
    {
        $env = array_map('strval', (array) ($spec['env'] ?? []));
        $port = (string) ($spec['port'] ?? $env['PORT'] ?? '');
        $get = fn (string $key, string $default = '') => $env[$key] ?? $default;
        $url = fn (string $scheme, string $user, string $password, string $path = '') => $scheme.'://'.rawurlencode($user).':'.rawurlencode($password)."@{$host}:{$port}{$path}";

        return match ($template) {
            'postgres' => [
                'DATABASE_URL' => $url('postgres', $get('POSTGRES_USER', 'postgres'), $get('POSTGRES_PASSWORD'), '/'.$get('POSTGRES_DB', 'postgres')),
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $host,
                'DB_PORT' => $port,
                'DB_DATABASE' => $get('POSTGRES_DB', 'postgres'),
                'DB_USERNAME' => $get('POSTGRES_USER', 'postgres'),
                'DB_PASSWORD' => $get('POSTGRES_PASSWORD'),
            ],
            'mysql', 'mariadb' => (function () use ($template, $get, $url, $host, $port) {
                $prefix = $template === 'mysql' ? 'MYSQL' : 'MARIADB';

                return [
                    'DATABASE_URL' => $url('mysql', $get("{$prefix}_USER"), $get("{$prefix}_PASSWORD"), '/'.$get("{$prefix}_DATABASE")),
                    'DB_CONNECTION' => $template,
                    'DB_HOST' => $host,
                    'DB_PORT' => $port,
                    'DB_DATABASE' => $get("{$prefix}_DATABASE"),
                    'DB_USERNAME' => $get("{$prefix}_USER"),
                    'DB_PASSWORD' => $get("{$prefix}_PASSWORD"),
                ];
            })(),
            'mongodb' => [
                'MONGODB_URI' => $url('mongodb', $get('MONGO_USER'), $get('MONGO_PASSWORD')),
            ],
            'redis', 'valkey' => (function () use ($template, $get, $host, $port) {
                $password = $get($template === 'redis' ? 'REDIS_PASSWORD' : 'VALKEY_PASSWORD');

                return [
                    'REDIS_URL' => 'redis://:'.rawurlencode($password)."@{$host}:{$port}",
                    'REDIS_HOST' => $host,
                    'REDIS_PORT' => $port,
                    'REDIS_PASSWORD' => $password,
                ];
            })(),
            'minio' => [
                'AWS_ENDPOINT' => "http://{$host}:{$port}",
                'AWS_ACCESS_KEY_ID' => $get('MINIO_ROOT_USER'),
                'AWS_SECRET_ACCESS_KEY' => $get('MINIO_ROOT_PASSWORD'),
                'AWS_DEFAULT_REGION' => 'us-east-1',
                'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
            ],
            'soketi' => [
                'PUSHER_APP_ID' => $get('APP_ID'),
                'PUSHER_APP_KEY' => $get('APP_KEY'),
                'PUSHER_APP_SECRET' => $get('APP_SECRET'),
                'PUSHER_HOST' => $host,
                'PUSHER_PORT' => $port,
                'PUSHER_SCHEME' => 'http',
            ],
            default => [],
        };
    }
}
