<?php

namespace App\Support\ServiceTemplates;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonFacts;
use App\Support\Deploys\DeployRequest;
use App\Support\Projects\Placement;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a service template and the deploy dialog's input into the daemon's
 * deploy job: resolves each setting (what the user typed, or a generated
 * password, key or URL), and sends the template's files along with it.
 *
 * Generated secrets are returned once, to show the user, and not stored.
 */
final class TemplateDeployment
{
    /** @var array<string, string> */
    public array $env = [];

    /** @var list<array{key: string, label: string, value: string}> */
    public array $generated = [];

    /**
     * Generated settings kept from the last deploy of this service instead
     * of made anew: a database only takes its password the first time its
     * data is created, so a new one on a redeploy would lock everything
     * out of it (and a new APP_KEY would break what's already encrypted).
     *
     * @var list<string>
     */
    public array $kept = [];

    /** @var array<string, string>|null */
    private ?array $previousEnv = null;

    public string $url;

    public int $port;

    /**
     * @param  array<string, mixed>  $input  validated: service, port, repo, commit, health_path, domain, env, extra_env
     */
    public function __construct(
        public readonly ServiceTemplate $template,
        public readonly Machine $machine,
        private readonly array $input,
    ) {
        $this->port = $template->portEditable && isset($input['port'])
            ? (int) $input['port']
            : $template->port;

        $domain = $this->domain();
        $this->url = $domain !== null
            ? "https://{$domain}"
            : $machine->localAddress($this->port) ?? 'http://'.($machine->ip_address ?: 'localhost').':'.$this->port;

        $this->resolveEnv();
    }

    /**
     * The job the gateway forwards to the daemon.
     *
     * @return array<string, mixed>
     */
    public function job(): array
    {
        $template = $this->template;

        return [
            'type' => 'deploy',
            'service' => $this->input['service'],
            'repo' => $template->isApp() ? $this->input['repo'] : '',
            'commit' => $this->commit(),
            'files' => (object) [...$this->files(), ...$this->buildEnvFile()],
            'compose_file' => $template->isApp() ? null : 'compose.yaml',
            'dockerfile' => $template->isApp() ? $template->dockerfile : null,
            'env' => (object) $this->env,
            'health' => $this->health(),
            'domains' => DeployRequest::domains($this->domain(), (bool) ($this->input['wildcard'] ?? false)),
            'port' => $this->port,
        ];
    }

    /**
     * The template's files, built on the PHP version asked for when it's
     * one of the PHP templates (they all build on serversideup/php).
     *
     * @return array<string, string>
     */
    private function files(): array
    {
        $php = $this->input['php'] ?? null;
        if (! is_string($php) || ! in_array($php, RepositoryDetector::PHP_VERSIONS, true)) {
            return $this->template->files;
        }

        return array_map(fn (string $file) => (string) preg_replace('#serversideup/php:\d+\.\d+-#', "serversideup/php:{$php}-", $file), $this->template->files);
    }

    /** Whether the template builds on serversideup/php, so a PHP version applies. */
    public static function usesPhp(ServiceTemplate $template): bool
    {
        return collect($template->files)->contains(fn (string $file) => str_contains($file, 'serversideup/php:'));
    }

    /** Variable names a frontend build bakes into the browser bundle. */
    private const PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    /**
     * The daemon's env file only reaches the running container, but frontend
     * frameworks read public variables while building. So app deploys also
     * get them as .env.production.local, which Next.js and Vite load at build
     * time with top priority (and which never replaces a committed
     * .env.production). Only public names go in: secrets stay out of the
     * built image.
     *
     * @return array<string, string>
     */
    private function buildEnvFile(): array
    {
        if (! $this->template->isApp()) {
            return [];
        }

        $lines = [];
        foreach ($this->env as $key => $value) {
            if (preg_match(self::PUBLIC_ENV, (string) $key)) {
                $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], (string) $value);
                $lines[] = "{$key}=\"{$escaped}\"";
            }
        }

        return $lines === [] ? [] : ['.env.production.local' => implode("\n", $lines)."\n"];
    }

    /**
     * App deploys build the user's commit. Template-only deploys are named by
     * a digest of the template, so redeploying an unchanged template reuses
     * its release name.
     */
    public function commit(): string
    {
        if ($this->template->isApp()) {
            return strtolower((string) $this->input['commit']);
        }

        return sha1($this->template->slug."\n".json_encode($this->template->files));
    }

    private function domain(): ?string
    {
        $domain = $this->input['domain'] ?? null;

        return $this->template->web && filled($domain) ? strtolower((string) $domain) : null;
    }

    /**
     * @return array{path: string, expected_status: int, timeout_secs: int, retries: int}|null
     */
    private function health(): ?array
    {
        if (! $this->template->web) {
            return null;
        }

        $path = $this->template->isApp() && filled($this->input['health_path'] ?? null)
            ? (string) $this->input['health_path']
            : $this->template->health;

        return $path === null ? null : [
            'path' => $path,
            'expected_status' => 200,
            'timeout_secs' => 30,
            // Template stacks pull images on first deploy, so allow longer,
            // and longer again for one that sets up on its first start.
            'retries' => $this->template->healthRetries ?? ($this->template->isApp() ? 5 : 8),
        ];
    }

    /**
     * The settings the last deploy of this service on this machine went out
     * with, failed or not (a database that failed its health check still
     * created its data).
     *
     * @return array<string, string>
     */
    private function previousEnv(): array
    {
        if ($this->previousEnv !== null) {
            return $this->previousEnv;
        }
        $service = $this->input['service'] ?? null;
        // Only while it's still there: its data (and the password it took
        // when that was made) still is. Removed, it starts fresh, with new
        // secrets, as someone replacing a leaked password would expect.
        $last = ! is_string($service) || $service === '' || Placement::containers($this->machine, $service) === [] ? null : DaemonJob::query()
            ->where('machine_id', $this->machine->id)
            ->where('kind', 'deploy')
            ->where('service', $service)
            ->whereNotIn('state', ['refused', 'cancelled'])
            ->latest()
            ->first();

        return $this->previousEnv = array_map('strval', (array) ($last?->command['job']['env'] ?? []));
    }

    private function resolveEnv(): void
    {
        $given = $this->input['env'] ?? [];
        $errors = [];

        foreach ($this->template->env as $field) {
            $key = $field['key'];
            $value = isset($given[$key]) ? trim((string) $given[$key]) : '';

            if ($field['accept'] ?? false) {
                if (! in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true)) {
                    $errors["env.{$key}"] = "You need to accept this to deploy {$this->template->name}.";
                }
                $this->env[$key] = 'TRUE';

                continue;
            }

            if ($value === '') {
                $default = $field['default'] ?? '';
                $value = $this->generate($default);

                if ($value !== $default && isset($this->previousEnv()[$key]) && $this->previousEnv()[$key] !== '') {
                    $value = $this->previousEnv()[$key];
                    $this->kept[] = $key;
                } elseif ($value !== $default && ($field['secret'] ?? false)) {
                    $this->generated[] = ['key' => $key, 'label' => $field['label'], 'value' => $value];
                }
            }

            if ($value === '' && ($field['required'] ?? false)) {
                $errors["env.{$key}"] = "{$field['label']} is required.";
            }

            $this->env[$key] = $value;
        }

        foreach ($this->parseExtraEnv($errors) as $key => $value) {
            $this->env[$key] = $value;
        }

        // The panel always says which port to use, and where Docker's
        // bridge is, for stacks that publish to apps on the machine.
        $this->env['PORT'] = (string) $this->port;
        $this->env['DOCKER_BRIDGE'] = DaemonFacts::dockerBridge($this->machine->facts ?? []);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Fill a default: `{random:N}`, `{base64:N}`, `{url}`, or a plain value.
     */
    private function generate(string $default): string
    {
        if (preg_match('/^\{(random|base64):([1-9]\d*)\}$/', $default, $m)) {
            $length = max(1, min(256, (int) $m[2]));

            return $m[1] === 'random'
                ? Str::random($length)
                : 'base64:'.base64_encode(random_bytes($length));
        }
        if ($default === '{url}') {
            return $this->url;
        }

        return $default;
    }

    /**
     * `KEY=value` lines from the dialog's extra environment box.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function parseExtraEnv(array &$errors): array
    {
        $env = [];

        foreach (preg_split('/\R/', (string) ($this->input['extra_env'] ?? '')) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $key = trim((string) $key);

            if ($value === null || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $errors['extra_env'] = 'Line '.($number + 1).' should look like KEY=value.';

                continue;
            }

            $env[$key] = trim($value);
        }

        return $env;
    }
}
