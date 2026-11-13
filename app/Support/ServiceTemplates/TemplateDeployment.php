<?php

namespace App\Support\ServiceTemplates;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonFacts;
use App\Support\Deploys\DeployRequest;
use App\Support\Projects\Placement;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TemplateDeployment
{
    /** @var array<string, string> */
    public array $env = [];

    /** @var list<array{key: string, label: string, value: string}> */
    public array $generated = [];

    // Reused on redeploy: a database takes its password only once, and a new APP_KEY breaks encrypted data.
    /** @var list<string> */
    public array $kept = [];

    /** @var array<string, string>|null */
    private ?array $previousEnv = null;

    public string $url;

    public int $port;

    /** @param  array<string, mixed>  $input */
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

    /** @return array<string, mixed> */
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

    /** @return array<string, string> */
    private function files(): array
    {
        $php = $this->input['php'] ?? null;
        if (! is_string($php) || ! in_array($php, RepositoryDetector::PHP_VERSIONS, true)) {
            return $this->template->files;
        }

        return array_map(fn (string $file) => (string) preg_replace('#serversideup/php:\d+\.\d+-#', "serversideup/php:{$php}-", $file), $this->template->files);
    }

    public static function usesPhp(ServiceTemplate $template): bool
    {
        return collect($template->files)->contains(fn (string $file) => str_contains($file, 'serversideup/php:'));
    }

    private const PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    /** @return array<string, string> */
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
            'retries' => $this->template->healthRetries ?? ($this->template->isApp() ? 5 : 8),
        ];
    }

    /** @return array<string, string> */
    private function previousEnv(): array
    {
        if ($this->previousEnv !== null) {
            return $this->previousEnv;
        }
        $service = $this->input['service'] ?? null;
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

        $this->env['PORT'] = (string) $this->port;
        $this->env['DOCKER_BRIDGE'] = DaemonFacts::dockerBridge($this->machine->facts ?? []);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

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
        // {jwt:role:KEY}: a long-lived HS256 token for role, signed with the value of the earlier field KEY.
        if (preg_match('/^\{jwt:([a-z_]+):([A-Z0-9_]+)\}$/', $default, $m) && ($this->env[$m[2]] ?? '') !== '') {
            $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode(['role' => $m[1], 'iss' => 'supabase', 'iat' => now()->timestamp, 'exp' => now()->addYears(10)->timestamp]);

            return $unsigned.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $unsigned, $this->env[$m[2]], true)), '+/', '-_'), '=');
        }

        return $default;
    }

    /**
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
