<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;

/**
 * Why a deploy failed, in plain words, with what fixes it. The daemon
 * reports where it stopped and the last lines of output, and its own
 * next step is generic ("Fix the build locally…"), or wrong for the
 * panel (it suggests deploy keys, which the panel doesn't use). The
 * common causes are recognised here from the error and the end of the
 * log; anything else keeps the daemon's words.
 *
 * Used by the deploy log, the failure alert and the MCP tools, so they
 * all say the same thing. When the fix is installing packages, "install"
 * says which, and the deploy log offers to do it and retry.
 */
final class DeployFailure
{
    /**
     * Docker from Ubuntu's own packages, with the build and Compose plugins
     * that docker.io leaves out. What the "Install Docker" button installs.
     */
    public const DOCKER_PACKAGES = ['docker.io', 'docker-buildx', 'docker-compose-v2'];

    /** How much of the log's end to read, past the error's own tail. */
    private const LOG_LINES = 60;

    /**
     * @return array{cause: string, fix: string, command?: string, alternative?: string, install?: array{label: string, packages: list<string>}}|null
     */
    public static function explain(DaemonJob $job): ?array
    {
        if (! in_array($job->kind, ['deploy', 'rollback'], true) || ! in_array($job->state, ['failed', 'timed_out', 'refused'], true)) {
            return null;
        }

        $error = is_array($job->error) ? $job->error : [];

        return self::fromError(
            is_string($error['phase'] ?? null) ? $error['phase'] : '',
            is_string($error['message'] ?? null) ? $error['message'] : '',
            [
                ...array_slice(array_values(array_filter($job->log ?? [], 'is_string')), -self::LOG_LINES),
                ...array_values(array_filter(is_array($error['output_tail'] ?? null) ? $error['output_tail'] : [], 'is_string')),
            ],
            $job->state === 'timed_out',
        );
    }

    /**
     * @param  list<string>  $output  the end of the log and the error's tail
     * @return array{cause: string, fix: string, command?: string, alternative?: string, install?: array{label: string, packages: list<string>}}|null
     */
    public static function fromError(string $phase, string $message, array $output, bool $timedOut = false): ?array
    {
        $text = $message."\n".implode("\n", $output);
        $has = fn (string $pattern): bool => preg_match($pattern, $text) === 1;

        // The machine's Docker setup, not the app.
        if ($has('/Failed to find executable docker|docker: (command )?not found/i')) {
            return [
                'cause' => 'Docker isn’t installed on this machine, and every deploy needs it.',
                'fix' => 'Install it here and ServerOS retries the deploy, or run this on the machine as root, then retry:',
                'command' => 'curl -fsSL https://get.docker.com | sh',
                'install' => ['label' => 'Install Docker', 'packages' => self::DOCKER_PACKAGES],
            ];
        }
        if ($has("/buildx component is missing|docker: 'compose' is not a docker command|unknown command: docker compose/i")) {
            return [
                'cause' => 'Docker is installed, but without its build and Compose plugins (Ubuntu’s docker.io leaves them out).',
                'fix' => 'Run this on the machine as root, then retry:',
                'command' => 'apt-get install -y docker-buildx docker-compose-v2',
                'alternative' => 'With Docker’s own packages instead: apt-get install -y docker-buildx-plugin docker-compose-plugin',
                'install' => ['label' => 'Install the plugins', 'packages' => ['docker-buildx', 'docker-compose-v2']],
            ];
        }

        // The snapshot taken before deploying.
        if ($phase === 'dump' || $phase === 'snapshot') {
            if ($has('/is not running|No such container/i')) {
                return [
                    'cause' => 'The snapshot before deploying couldn’t be taken: the app’s container isn’t running, so its data can’t be copied. Nothing was deployed.',
                    'fix' => 'Start the service and deploy again, or turn off “Snapshot data before each deploy” on the app’s page.',
                ];
            }
            if ($has('/no space left on device/i')) {
                return [
                    'cause' => 'The machine ran out of disk space taking the snapshot before deploying. Nothing was deployed.',
                    'fix' => 'Free some space (old Docker images are often the biggest: “docker system prune”), or turn off “Snapshot data before each deploy” on the app’s page.',
                    'command' => 'docker system prune',
                ];
            }

            return null;
        }

        if ($has('/no space left on device/i')) {
            return [
                'cause' => 'The machine ran out of disk space during the deploy.',
                'fix' => 'Free some space, then retry. Old Docker images and build cache are often the biggest:',
                'command' => 'docker system prune',
            ];
        }
        if ($has('/JavaScript heap out of memory|Cannot allocate memory|\bENOMEM\b|exit code:? 137|exited 137|\bOOMKilled\b|^\s*Killed\s*$/im')) {
            return [
                'cause' => 'The build ran out of memory, so Linux stopped it.',
                'fix' => 'Add swap or move to a machine with more memory. For Node builds, setting NODE_OPTIONS=--max-old-space-size to a bit under the machine’s memory can help.',
            ];
        }

        // Getting the code.
        if ($phase === 'fetch' && $has('/Permission denied|could not read Username|Repository not found|Authentication failed/i')) {
            return [
                'cause' => 'The repository couldn’t be read: it’s private, or the address is wrong.',
                'fix' => 'If it’s private, connect GitHub in Settings → General and give the ServerOS app access to this repository, then deploy again. Otherwise check the address opens in a private browser window.',
            ];
        }
        if ($has('/Could not resolve host|Temporary failure in name resolution|getaddrinfo (EAI_AGAIN|ENOTFOUND)|Network is unreachable|i\/o timeout|TLS handshake timeout/i')) {
            return [
                'cause' => 'The machine couldn’t reach the internet to download what the build needs.',
                'fix' => 'Check the machine’s DNS and outbound network (try “curl -I https://github.com” on it), then retry.',
            ];
        }

        // Base images.
        if ($has('/toomanyrequests|You have reached your (unauthenticated )?pull rate limit/i')) {
            return [
                'cause' => 'Docker Hub refused to send the image: this machine hit its download limit.',
                'fix' => 'Wait a few hours and retry, or sign in to Docker Hub on the machine (“docker login”) for a higher limit.',
            ];
        }
        if ($has('/pull access denied|manifest unknown|manifest for \S+ not found|repository does not exist or may require/i')) {
            return [
                'cause' => 'An image the build needs couldn’t be downloaded: the name or tag is wrong, or the image is private.',
                'fix' => 'Check the FROM lines in the Dockerfile (or the images in the Compose file) exist on Docker Hub with that tag.',
            ];
        }

        // Ports.
        if ($has('/port is already allocated|address already in use|EADDRINUSE/i')) {
            return [
                'cause' => 'The port the app needs is already taken by something else on this machine.',
                'fix' => 'Pick another port for the app, or stop what’s using it. “ss -ltnp” on the machine shows what’s listening where.',
                'command' => 'ss -ltnp',
            ];
        }
        if ($has('/failed to bind host port .*cannot assign requested address/i')) {
            return [
                'cause' => 'The app was set to listen on an address this machine doesn’t have (Docker’s network is set up differently here).',
                'fix' => 'Deploy it again from the one-click app: ServerOS now finds the right address for each machine.',
            ];
        }

        // The web server in front, for apps with a domain.
        if ($phase === 'proxy' && $has('/localhost:2019.*connection refused|is not running and would not start/i')) {
            return [
                'cause' => 'The app is running, but Caddy (the web server that gives it HTTPS) isn’t, so its domain couldn’t be switched on.',
                'fix' => 'Start Caddy on the machine and retry. If it won’t start, something else is probably using port 80 or 443 (“ss -ltnp” shows what), or “journalctl -u caddy” says why.',
                'command' => 'systemctl enable --now caddy',
            ];
        }

        // The build itself.
        if ($has('/Missing script: "?build"?/i')) {
            return [
                'cause' => 'The build runs “npm run build”, but package.json has no build script.',
                'fix' => 'Add a "build" script to package.json, or remove the build step from the Dockerfile, then push and redeploy.',
            ];
        }
        if ($has('/can only install packages when your package\.json and package-lock\.json.* are in sync|npm ci.* can only install with an existing package-lock/is')) {
            return [
                'cause' => 'package-lock.json is missing or doesn’t match package.json, so “npm ci” won’t install.',
                'fix' => 'Run “npm install” locally, commit the updated package-lock.json, push and redeploy.',
            ];
        }
        if ($has('/The engine "node" is incompatible|Unsupported engine|requires Node\.js|Node\.js version .* is required/i')) {
            return [
                'cause' => 'The project needs a different version of Node.js than the build used.',
                'fix' => 'Change the Node version in the Dockerfile’s FROM line (for example node:22-alpine) to one the project supports.',
            ];
        }
        if ($has('/requires php [^\n]*your php version|Root composer\.json requires php/i')) {
            return [
                'cause' => 'The project needs a different version of PHP than the build used.',
                'fix' => 'Change the PHP version in the Dockerfile’s FROM line to the one composer.json asks for.',
            ];
        }
        if ($has('/Your requirements could not be resolved|Your lock file does not contain a compatible set of packages/i')) {
            return [
                'cause' => 'Composer couldn’t install the PHP packages: composer.lock doesn’t fit this PHP version or its extensions.',
                'fix' => 'The lines above say which package and why. Usually it’s a missing PHP extension to add in the Dockerfile, or a lock file to update with “composer update” locally.',
            ];
        }
        if ($has('/No matching distribution found for|Could not find a version that satisfies the requirement/i')) {
            return [
                'cause' => 'pip couldn’t find a Python package the project needs, at that version.',
                'fix' => 'Check requirements.txt: the version may not exist, or may need a newer Python in the Dockerfile.',
            ];
        }
        if ($has('/failed to compute cache key|COPY failed|failed to calculate checksum of ref .*not found/i')) {
            return [
                'cause' => 'The Dockerfile copies a file that isn’t in the repository.',
                'fix' => 'It’s often ignored by .gitignore or .dockerignore, or only exists on your computer. Commit it, or change the COPY line, then push and redeploy.',
            ];
        }
        $variable = self::missingVariable($text);
        if ($variable !== null) {
            return [
                'cause' => $variable !== '' ? "The app needs the environment variable {$variable}, and it isn’t set." : 'The app needs an environment variable that isn’t set.',
                'fix' => 'Add it to the app’s environment variables, then deploy again.',
            ];
        }

        // Starting up.
        if ($phase === 'health') {
            if ($has('/not accepting connections|Connection refused/i')) {
                return [
                    'cause' => 'The app started, but nothing answered on its port, so the new version was never switched in. The old one, if any, is still running.',
                    'fix' => 'Make the app listen on 0.0.0.0 (not localhost or 127.0.0.1) and on the port set for it. The app’s logs show whether it crashed on start.',
                ];
            }
            if ($has('/returned HTTP 5\d\d/i')) {
                return [
                    'cause' => 'The app started but answers with an error, so the new version was never switched in. The old one, if any, is still running.',
                    'fix' => 'The app’s logs say why. Usually it’s a missing environment variable, or a database it can’t reach.',
                ];
            }

            return [
                'cause' => 'The new version never answered its health check, so it was never switched in. The old one, if any, is still running.',
                'fix' => 'Check the app listens on 0.0.0.0 on its port, answers the health check path, and starts within about a minute. Its logs show what it did.',
            ];
        }

        if ($timedOut || $has('/ timed out$/m')) {
            return [
                'cause' => 'The deploy took too long and was stopped.',
                'fix' => 'Builds that download a lot or compile from source can be slow on small machines. Retry once (later builds reuse what was downloaded), or slim the build down.',
            ];
        }

        return null;
    }

    /**
     * The environment variable an app said it was missing: its name, '' when
     * it didn't say which, or null when nothing was missing.
     */
    private static function missingVariable(string $text): ?string
    {
        if (str_contains($text, 'No application encryption key has been specified')) {
            return 'APP_KEY';
        }
        $patterns = [
            '/Missing (?:required )?environment variables?:?\s*["\'`]?([A-Z][A-Z0-9_]{2,})/i',
            '/environment variable\s+["\'`]?([A-Z][A-Z0-9_]{2,})["\'`]?\s+(?:is\s+)?(?:not set|missing|required|undefined)/i',
            '/KeyError: [\'"]([A-Z][A-Z0-9_]{2,})[\'"]/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
