<?php

namespace App\Support\DeploySources;

use App\Models\DeploySource;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Turns an uploaded .zip into a one-commit bare Git repository the daemon can
 * fetch like any other, and works out what kind of project it is.
 *
 * The zip is untrusted: entry names can't climb out of the folder, symlinks
 * are skipped, and the number of files and unpacked size are capped so a zip
 * bomb can't fill the disk. Dependencies, build output, Git metadata and
 * .env files are left out: the build installs dependencies itself, and
 * secrets belong in the deploy's environment, not in the code.
 */
class DeploySourcePacker
{
    public const MAX_FILES = 50_000;

    public const MAX_UNPACKED_BYTES = 4 * 1024 * 1024 * 1024;

    /** Folders never worth shipping. Matched against every path segment. */
    private const SKIP_DIRECTORIES = ['node_modules', '.git', '.next', '.nuxt', '.svelte-kit', '.turbo', '.vercel', '.cache', '__MACOSX', 'vendor'];

    private const SKIP_FILES = ['.DS_Store', 'Thumbs.db'];

    public function __construct(private RepositoryDetector $detector) {}

    public function pack(DeploySource $source): void
    {
        $work = $source->directory().'/work';
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        try {
            $count = $this->extract($source->zipPath(), $work);
            $root = $this->unwrap($work);

            $this->git($root, ['init', '-q', '-b', 'main']);
            $this->git($root, ['add', '-A']);
            $this->git($root, ['-c', 'user.name=ServerOS', '-c', 'user.email=deploys@serveros.com', 'commit', '-q', '-m', 'Uploaded '.$source->filename]);
            $commit = trim($this->git($root, ['rev-parse', 'HEAD']));

            File::deleteDirectory($source->repoPath());
            $this->git($source->directory(), ['clone', '-q', '--bare', $root, $source->repoPath()]);

            $source->forceFill([
                'status' => 'ready',
                'commit' => $commit,
                'files' => $count,
                'detection' => $this->detect($root),
            ])->save();
        } finally {
            File::deleteDirectory($work);
            File::delete($source->zipPath());
        }
    }

    /**
     * A new commit with the same files, so the same code can be deployed
     * again. The daemon names each release by its commit, so redeploying
     * the live commit itself would replace the running container.
     */
    public function recommit(DeploySource $source, string $message): string
    {
        $repo = $source->repoPath();
        $commit = trim($this->git($repo, [
            '-c', 'user.name=ServerOS', '-c', 'user.email=deploys@serveros.com',
            'commit-tree', 'HEAD^{tree}', '-p', 'HEAD', '-m', $message,
        ]));
        $this->git($repo, ['update-ref', 'HEAD', $commit]);
        $source->forceFill(['commit' => $commit])->save();

        return $commit;
    }

    /** Unpacks the zip into $into, returning how many files were kept. */
    private function extract(string $zipPath, string $into): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw $this->fail('That file isn’t a zip we can open.');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw $this->fail('That zip has more than '.number_format(self::MAX_FILES).' files. Leave out node_modules and build folders.');
            }

            $total = 0;
            $kept = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw $this->fail('Couldn’t read every file in that zip.');
                }
                $name = str_replace('\\', '/', $stat['name']);

                if ($this->skipped($name)) {
                    continue;
                }
                if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, "\0")) {
                    throw $this->fail('That zip has a file path that points outside the project.');
                }

                // Unix mode lives in the top 16 bits; 0120000 marks a symlink.
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    continue;
                }

                $target = $into.'/'.$name;
                if (str_ends_with($name, '/')) {
                    File::ensureDirectoryExists($target);

                    continue;
                }

                $total += $stat['size'];
                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw $this->fail('That zip unpacks to more than 4 GB. Leave out node_modules and build folders.');
                }

                File::ensureDirectoryExists(dirname($target));
                $in = $zip->getStream($stat['name']);
                $out = fopen($target, 'wb');
                if ($in === false || $out === false) {
                    throw $this->fail('Couldn’t read every file in that zip.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $kept++;
            }

            if ($kept === 0) {
                throw $this->fail('That zip is empty once dependencies and build folders are left out.');
            }

            return $kept;
        } finally {
            $zip->close();
        }
    }

    private function skipped(string $name): bool
    {
        $segments = array_filter(explode('/', $name), fn ($segment) => $segment !== '');
        $file = end($segments) ?: '';

        foreach ($segments as $segment) {
            if (in_array($segment, self::SKIP_DIRECTORIES, true)) {
                return true;
            }
        }

        // .env files hold secrets; .env.example is fine to keep.
        if (! str_ends_with($name, '/') && preg_match('/^\.env(\..+)?$/', $file) && ! preg_match('/\.(example|sample|dist)$/', $file)) {
            return true;
        }

        return in_array($file, self::SKIP_FILES, true);
    }

    /** A zip of a folder holds one folder: use what's inside it. */
    private function unwrap(string $work): string
    {
        $entries = array_values(array_diff(scandir($work) ?: [], ['.', '..']));

        return count($entries) === 1 && is_dir($work.'/'.$entries[0])
            ? $work.'/'.$entries[0]
            : $work;
    }

    /** @return array<string, mixed> */
    private function detect(string $root): array
    {
        $files = array_values(array_diff(scandir($root) ?: [], ['.', '..', '.git']));
        $contents = [];
        foreach (RepositoryDetector::MANIFESTS as $manifest) {
            if (is_file($root.'/'.$manifest)) {
                $contents[$manifest] = (string) file_get_contents($root.'/'.$manifest, length: 512 * 1024);
            }
        }

        $match = $this->detector->match($files, $contents);

        return [
            'detected' => $match !== null,
            'template' => $match['template'] ?? null,
            'framework' => $match['framework'] ?? null,
            'type' => $match['type'] ?? null,
            'reasons' => $match['reasons'] ?? [],
        ];
    }

    /** @param  list<string>  $args */
    private function git(string $cwd, array $args): string
    {
        $result = Process::path($cwd)->timeout(300)->env(['GIT_TERMINAL_PROMPT' => '0'])->run(['git', ...$args]);
        if ($result->failed()) {
            report(new \RuntimeException('git '.implode(' ', $args).' failed: '.$result->errorOutput()));

            throw $this->fail('Couldn’t prepare that upload. Try again, or contact support.');
        }

        return $result->output();
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
