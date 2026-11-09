<?php

namespace App\Support\ServiceTemplates;

/**
 * The page at /apps/{slug}: how to run one template's app on your own
 * server, with ServerOS and by hand, written from the template itself so
 * every one-click app has a page that matches what the panel deploys.
 *
 * By hand is the template's own files plus a .env that fills in what the
 * panel would (the port, Docker's bridge, the settings and their
 * generated secrets), so the Compose file shown is the one ServerOS runs.
 *
 * @phpstan-type Page array{
 *     slug: string,
 *     name: string,
 *     icon: string,
 *     category: string,
 *     kind: string,
 *     title: string,
 *     description: string,
 *     lead: string,
 *     intro: string,
 *     settings: list<array{0: string, 1: string}>,
 *     steps: list<array{0: string, 1: string}>,
 *     byHand: string,
 *     files: list<array{0: string, 1: string}>,
 *     commands: string,
 *     notes: ?string,
 *     faq: list<array{0: string, 1: string}>
 * }
 */
final class TemplateGuide
{
    /** Titles for templates whose names don't read as "a {name} app". */
    private const TITLES = [
        'static-site' => 'How to deploy a static site on your own server',
        'vite-spa' => 'How to deploy a Vite app on your own server',
        'custom-dockerfile' => 'How to deploy any Dockerfile on your own server',
    ];

    /** Docker's usual bridge address, for pages that aren't about one machine. */
    private const BRIDGE = '172.17.0.1';

    /**
     * @return Page
     */
    public static function for(ServiceTemplate $template): array
    {
        $guide = new self($template);

        return [
            'slug' => $template->slug,
            'name' => $template->name,
            'icon' => $template->icon,
            'category' => $template->category,
            'kind' => $template->kind,
            'title' => $guide->title(),
            'description' => $guide->metaDescription(),
            'lead' => $template->description,
            'intro' => $guide->intro(),
            'settings' => $guide->settings(),
            'steps' => $guide->steps(),
            'byHand' => $guide->byHand(),
            'files' => $guide->files(),
            'commands' => $guide->commands(),
            'notes' => $template->notes === null ? null : str_replace('{bridge}', "Docker's bridge address (usually ".self::BRIDGE.')', $template->notes),
            'faq' => $guide->faq(),
        ];
    }

    private function __construct(private readonly ServiceTemplate $template) {}

    private function isGame(): bool
    {
        return $this->template->category === 'Game servers';
    }

    private function compose(): string
    {
        return $this->template->files['compose.yaml'] ?? '';
    }

    public function title(): string
    {
        $name = $this->template->name;

        if (isset(self::TITLES[$this->template->slug])) {
            return self::TITLES[$this->template->slug];
        }
        $plain = str_replace(': ', ' ', $name);
        $a = preg_match('/^([AEIO]|Un)/i', $plain) ? 'an' : 'a';

        return match (true) {
            $this->template->isApp() => "How to deploy {$a} {$plain} app on your own server",
            $this->isGame() => "How to host {$a} {$plain} server",
            $this->template->category === 'Databases' => "How to run {$name} on your own server",
            default => "How to self-host {$name}",
        };
    }

    private function metaDescription(): string
    {
        $name = $this->template->name;

        if ($this->template->isApp()) {
            return "Deploy your {$name} app to a VPS you rent: the Dockerfile it needs, the settings, and how to run it with ServerOS from GitHub or by hand with Docker.";
        }

        return "Run {$name} on a VPS you rent: the Docker Compose file, the settings it needs, and how to set it up in one click with ServerOS or by hand.";
    }

    private function intro(): string
    {
        $name = $this->template->name;
        $rest = rtrim($this->template->description, '.');

        if ($this->template->isApp()) {
            return "{$rest}. Any Linux server with Docker can run it: a VPS from Hetzner, DigitalOcean, OVH or anyone else, or a computer under your desk. Your code stays in your repository, the server stays on your account, and the app keeps running whatever happens to ServerOS.";
        }
        if ($this->isGame()) {
            return "{$rest}. A rented server keeps the world online when your own computer is off, with nobody's PC paying for it in lag. It runs in Docker on any Linux server, and stays yours: your provider, your bill, your saves.";
        }

        return "{$name} runs in Docker on any Linux server: a VPS from Hetzner, DigitalOcean, OVH or anyone else, or a computer under your desk. Your data stays on that server, on your account.";
    }

    /**
     * What the deploy dialog asks for, and what each one is.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function settings(): array
    {
        return array_map(function (array $field): array {
            $default = (string) ($field['default'] ?? '');
            $about = match (true) {
                (bool) preg_match('/^\{(random|base64):\d+\}$/', $default) => 'A random value is made for you.',
                $default === '{url}' => 'The address it\'s reached at, from the domain you add.',
                $default !== '' => "Starts as {$default}.",
                (bool) ($field['required'] ?? false) => 'Required.',
                default => 'Optional.',
            };
            if (filled($field['hint'] ?? null)) {
                $about .= ' '.$field['hint'];
            }

            return [$field['label'], $about];
        }, $this->template->env);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function steps(): array
    {
        $name = $this->template->name;
        $connect = ['Connect your server', 'Install the ServerOS daemon with one command from the panel. It connects out to ServerOS, so there are no ports to open, and it finds what the server already runs.'];

        if ($this->template->isApp()) {
            return [
                $connect,
                ['Deploy from GitHub', "Open the machine, press Deploy service and choose Custom. Paste your repository's link. ServerOS reads the latest commit and works out it's {$name}."],
                ['Check the settings', $this->settingsSentence()],
                ['Add a domain', 'Type the domain you want. ServerOS shows the DNS record to add and sets up HTTPS with Let\'s Encrypt once it points at the machine.'],
                ['Deploy', 'ServerOS builds it, starts it and checks it answers before sending traffic to it. Turn on deploy on push and every push to your branch goes live the same way.'],
            ];
        }

        $steps = [
            $connect,
            ["Pick {$name}", "Open the machine, press Deploy service and search for {$name}."],
            ['Check the settings', $this->settingsSentence()],
        ];
        if ($this->template->web) {
            $steps[] = ['Add a domain', 'Type the domain you want. ServerOS shows the DNS record to add and sets up HTTPS with Let\'s Encrypt once it points at the machine.'];
        }
        $steps[] = ['Deploy', $this->isGame()
            ? 'Press Deploy and follow it on the machine\'s Deployments tab. The server shows up with its CPU and memory, and you can restart it, read its logs and back up its world from the panel.'
            : "Press Deploy and follow it on the machine's Deployments tab. {$name} shows up with the machine's other services, with its logs, its CPU and memory, and backups."];

        return $steps;
    }

    private function settingsSentence(): string
    {
        $labels = array_column($this->template->env, 'label');
        $secret = array_filter($this->template->env, fn (array $field) => (bool) preg_match('/^\{(random|base64):\d+\}$/', (string) ($field['default'] ?? '')));

        if ($labels === []) {
            return 'There\'s nothing to fill in. Change the port if something else on the machine already uses it.';
        }

        $list = count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels)
            : $labels[0];
        $sentence = "The dialog asks for {$list}.";
        if ($secret !== []) {
            $sentence .= ' Passwords and keys are filled in with random ones. Copy them before you deploy.';
        }

        return $sentence;
    }

    private function byHand(): string
    {
        if ($this->template->isApp()) {
            return 'Add this Dockerfile to your project, build the image on the server, and run it with your settings in a .env file. It listens on the machine only, so put a reverse proxy like Caddy or nginx in front of it for your domain and HTTPS.';
        }

        $text = 'Install Docker, make a folder with these files, and start it with Docker Compose. The Compose file is the one ServerOS runs, and the .env file fills in what the panel would.';
        if ($this->template->web && $this->isPrivate()) {
            $text .= ' It listens on the machine only, so put a reverse proxy like Caddy or nginx in front of it for your domain and HTTPS.';
        } elseif ($this->isGame()) {
            $text .= ' Open its ports in your firewall, and in your provider\'s firewall if it has one.';
        }

        return $text;
    }

    /** Whether every port it publishes is on the machine only (or Docker's bridge). */
    private function isPrivate(): bool
    {
        preg_match_all('/^\s*-\s*"?([^"\s]+)"?\s*$/m', $this->portsBlock(), $matches);
        if ($matches[1] === []) {
            return true;
        }

        foreach ($matches[1] as $mapping) {
            if (! str_starts_with($mapping, '127.0.0.1:') && ! str_starts_with($mapping, '${DOCKER_BRIDGE}:')) {
                return false;
            }
        }

        return true;
    }

    /** Every line under a `ports:` key in the Compose file. */
    private function portsBlock(): string
    {
        preg_match_all('/^(\s*)ports:\s*\n((?:\1\s+-.*\n?)+)/m', $this->compose(), $matches);

        return implode("\n", $matches[2]);
    }

    /**
     * The files to make, and the .env that fills them in.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function files(): array
    {
        $files = [];
        foreach ($this->template->files as $path => $contents) {
            $files[] = [$path === '.serveros/Dockerfile' ? 'Dockerfile' : $path, rtrim($contents)."\n"];
        }
        $files[] = ['.env', $this->dotEnv()];

        return $files;
    }

    private function dotEnv(): string
    {
        $lines = [];
        $keys = [];
        foreach ($this->template->env as $field) {
            $keys[] = $field['key'];
            $default = (string) ($field['default'] ?? '');
            if ($field['accept'] ?? false) {
                $lines[] = '# '.$field['label'].': TRUE once you have';
                $lines[] = "{$field['key']}=";
            } elseif (preg_match('/^\{random:(\d+)\}$/', $default, $m)) {
                $bytes = (int) ceil(((int) $m[1]) / 2);
                $lines[] = "# Make one: openssl rand -hex {$bytes}";
                $lines[] = "{$field['key']}=";
            } elseif (preg_match('/^\{base64:(\d+)\}$/', $default, $m)) {
                $lines[] = "# Make one: echo \"base64:$(openssl rand -base64 {$m[1]})\"";
                $lines[] = "{$field['key']}=";
            } elseif ($default === '{url}') {
                $lines[] = "{$field['key']}=https://{$this->template->slug}.example.com";
            } else {
                if ($default === '') {
                    $lines[] = '# '.rtrim($field['label'].(filled($field['hint'] ?? null) ? ': '.$field['hint'] : ''), '.');
                }
                $lines[] = "{$field['key']}={$default}";
            }
        }

        $used = $this->composeVariables();
        if (in_array('PORT', $used, true) || $this->template->isApp()) {
            $lines[] = "PORT={$this->template->port}";
        }
        if (in_array('DOCKER_BRIDGE', $used, true)) {
            $lines[] = '# Docker\'s bridge address: ip -4 addr show docker0';
            $lines[] = 'DOCKER_BRIDGE='.self::BRIDGE;
        }
        foreach (array_diff($used, [...$keys, 'PORT', 'DOCKER_BRIDGE']) as $key) {
            $lines[] = "{$key}=";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The ${NAME} variables the files use, without ones that bring their
     * own default (${NAME:-value}).
     *
     * @return list<string>
     */
    private function composeVariables(): array
    {
        if ($this->template->isApp()) {
            return [];
        }

        preg_match_all('/\$\{([A-Z0-9_]+)(:?-[^}]*)?\}/', implode("\n", $this->template->files), $matches, PREG_SET_ORDER);
        $names = [];
        foreach ($matches as $match) {
            if (($match[2] ?? '') === '') {
                $names[] = $match[1];
            }
        }

        return array_values(array_unique($names));
    }

    private function commands(): string
    {
        $slug = $this->template->slug;
        $port = $this->template->port;

        if ($this->template->isApp()) {
            return implode("\n", [
                '# In your project, with the Dockerfile and .env beside your code',
                "docker build -t {$slug}-app .",
                "docker run -d --name {$slug}-app --restart unless-stopped \\",
                "  --env-file .env -p 127.0.0.1:{$port}:{$port} {$slug}-app",
                '',
                '# HTTPS on your domain, with Caddy',
                "caddy reverse-proxy --from app.example.com --to 127.0.0.1:{$port}",
            ])."\n";
        }

        $lines = [
            '# Docker first, if the server hasn\'t got it',
            'curl -fsSL https://get.docker.com | sudo sh',
            '',
            "mkdir {$slug} && cd {$slug}",
            '# Save the files above here, then',
            'docker compose up -d',
            'docker compose logs -f',
        ];
        if ($this->template->web && $this->isPrivate()) {
            $lines[] = '';
            $lines[] = '# HTTPS on your domain, with Caddy';
            $lines[] = "caddy reverse-proxy --from {$slug}.example.com --to 127.0.0.1:{$port}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function faq(): array
    {
        $name = $this->template->name;
        $faq = [];

        if (! $this->template->isApp()) {
            $faq[] = ["What port does {$name} use?", $this->portAnswer()];
        }

        $volumes = $this->volumes();
        if ($volumes !== []) {
            $list = implode(', ', array_map(fn (string $v) => "`{$v}`", $volumes));
            $faq[] = ["Where does {$name} keep its data?", 'In Docker volumes ('.$list.'), so it survives restarts, updates and the container being recreated. `docker volume ls` lists them. Removing the stack with `docker compose down -v` deletes them, so leave out `-v`.'];
        }

        $faq[] = $this->template->isApp()
            ? ['How do I update it?', 'Push to your repository. With deploy on push turned on, ServerOS builds the new commit, checks it answers and only then switches traffic to it, so a broken build never goes live. By hand, pull your code, build the image again and recreate the container.']
            : ["How do I update {$name}?", 'Run `docker compose pull` then `docker compose up -d` in its folder. If the image is pinned to a version in the Compose file, change the tag first. ServerOS takes a snapshot of a service\'s data before each deploy, so an update that goes wrong can be undone.'];

        if ($volumes !== []) {
            $faq[] = ["How do I back up {$name}?", 'Copy its volumes somewhere other than the server, on a schedule. ServerOS Backups do that for every service it runs: daily or weekly, to an S3 bucket or another of your machines, with restores from the panel.'];
        }

        $faq[] = ['Does ServerOS host it for me?', 'No. It runs on a server you rent from any provider, on your account. ServerOS is the panel: it deploys it, watches it, backs it up and shows its logs, and if you stop using ServerOS it keeps running.'];

        return $faq;
    }

    private function portAnswer(): string
    {
        $port = $this->template->port;

        if ($this->isGame()) {
            return "It's set up on port {$port}. Players need the game's ports open in your firewall and your provider's.".($this->template->notes !== null ? ' The notes on this page list them all.' : '');
        }
        if ($this->isPrivate()) {
            return $this->template->web
                ? "Port {$port}, on the machine only. Visitors reach it through a reverse proxy on your domain with HTTPS, which ServerOS sets up when you add one. You can change the port if something else uses it."
                : "Port {$port}, on the machine only, so it isn't reachable from the internet. Apps on the same machine connect to it there, and apps in Docker through Docker's bridge address.";
        }

        return "Port {$port}.";
    }

    /** @return list<string> */
    private function volumes(): array
    {
        if (! preg_match('/^volumes:\s*\n((?:[ \t]+.*\n?)+)/m', $this->compose(), $match)) {
            return [];
        }
        preg_match_all('/^  ([A-Za-z0-9_.-]+):/m', $match[1], $names);

        return $names[1];
    }
}
