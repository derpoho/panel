<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DaemonRelease;
use App\Support\DaemonUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The daemon's update feed. CI uploads each signed release's files and
 * then registers it (both with the deploy token); daemons ask for the
 * latest on their channel and install it themselves, signature checked
 * against the key built into them.
 */
class DaemonReleaseController extends Controller
{
    private const VERSION = '/^v?\d+\.\d+\.\d+([-+][0-9A-Za-z.\-+]+)?$/';

    private const MAX_FILE = 64 * 1024 * 1024;

    public function latest(Request $request): JsonResponse
    {
        $channel = in_array($request->query('channel'), DaemonRelease::CHANNELS, true) ? $request->query('channel') : 'stable';
        $arch = DaemonRelease::arch((string) $request->query('arch', ''));
        abort_if($arch === null, 404);

        $release = DaemonRelease::latest($channel, $arch);
        abort_if($release === null, 404);

        return response()->json($release->toCandidate());
    }

    /**
     * Store one of a release's files (a binary or SHA256SUMS) where the
     * releases address serves it from. The body is the file itself, so
     * PHP's upload size limit doesn't apply.
     */
    public function upload(Request $request, string $version, string $file): JsonResponse
    {
        $this->authorizeDeploy($request);
        abort_unless(preg_match(self::VERSION, $version) === 1, 422, 'That isn’t a version.');
        $binary = collect(DaemonRelease::ARCHES)->contains(fn (string $arch) => $file === DaemonRelease::fileName($version, $arch));
        abort_unless($binary || $file === 'SHA256SUMS', 422, 'Upload serverosd-<version>-linux-<arch> or SHA256SUMS.');

        $body = $request->getContent();
        abort_if($body === '', 422, 'The file was empty.');
        abort_if(strlen($body) > self::MAX_FILE, 413);

        $path = DaemonRelease::path($version, $file);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path.'.part', $body);
        rename($path.'.part', $path);
        chmod($path, 0644);

        return response()->json(['file' => $file, 'size' => strlen($body), 'sha256' => hash('sha256', $body)], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeDeploy($request);

        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:'.self::VERSION],
            'arch' => ['required', Rule::in(DaemonRelease::ARCHES)],
            'channel' => ['required', Rule::in(DaemonRelease::CHANNELS)],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/i'],
            'signature' => ['required', 'string', 'max:200'],
            'min_from' => ['nullable', 'string', 'max:40'],
            'notes_url' => ['nullable', 'url', 'max:255'],
        ]);

        // Never offer daemons a download that isn't there.
        $release = new DaemonRelease($data);
        if (! $release->binaryIsServed()) {
            return response()->json(['errors' => ['sha256' => ['Upload '.DaemonRelease::fileName($data['version'], $data['arch']).' first: it isn’t here, or its checksum doesn’t match.']]], 422);
        }

        $release = DaemonRelease::query()->updateOrCreate(
            ['version' => $data['version'], 'arch' => $data['arch']],
            $data,
        );
        DaemonRelease::publishStableVersion();

        // Pushed straight away, not at each daemon's next check.
        if ($release->channel === 'stable') {
            $arch = $release->arch;
            defer(fn () => app(DaemonUpdates::class)->push($arch));
        }

        return response()->json($release->toCandidate(), $release->wasRecentlyCreated ? 201 : 200);
    }

    private function authorizeDeploy(Request $request): void
    {
        $token = (string) config('daemon.deploy_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);
    }
}
