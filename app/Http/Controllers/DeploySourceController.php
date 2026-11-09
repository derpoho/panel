<?php

namespace App\Http\Controllers;

use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\DeploySources\DeploySourcePacker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Uploading a project as a .zip to deploy from, in pieces small enough for
 * PHP's default request limits: start, send each piece, then finish, which
 * unpacks it and says what kind of project it is. The answer has the same
 * shape as a GitHub link's detection, so the deploy dialog carries on the
 * same way.
 */
class DeploySourceController extends Controller
{
    #[NotAudited('Stages an upload; the deploy that uses it is recorded, naming the file')]
    public function start(Request $request, Workspace $workspace, Machine $machine): JsonResponse
    {
        $this->authorize($workspace, $machine);

        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/\.zip$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.DeploySource::MAX_BYTES],
        ], [
            'filename.regex' => 'Upload a .zip of your project folder.',
            'size.max' => 'That zip is over 1 GB. Leave out node_modules and build folders.',
        ]);

        $source = new DeploySource([
            'token' => Str::random(48),
            'filename' => basename($data['filename']),
            'size' => (int) $data['size'],
            'expires_at' => now()->addHours(DeploySource::KEEP_HOURS),
        ]);
        $source->workspace()->associate($workspace);
        $source->forceFill(['user_id' => $request->user()->id])->save();
        File::ensureDirectoryExists($source->directory());

        return response()->json(['id' => $source->token, 'chunk' => DeploySource::CHUNK_BYTES], 201);
    }

    /** One piece of the file, as the raw request body, at the offset given. */
    #[NotAudited('Stages an upload; the deploy that uses it is recorded, naming the file')]
    public function chunk(Request $request, Workspace $workspace, Machine $machine, string $token): JsonResponse
    {
        $this->authorize($workspace, $machine);
        $source = $this->uploading($workspace, $token);

        $offset = (int) $request->query('offset', '-1');
        $body = $request->getContent();

        if ($offset !== $source->received) {
            return response()->json(['message' => 'Out of order.', 'received' => $source->received], 409);
        }
        if ($body === '' || strlen($body) > DeploySource::CHUNK_BYTES || $source->received + strlen($body) > $source->size) {
            throw ValidationException::withMessages(['file' => 'That upload got mixed up. Try again.']);
        }

        file_put_contents($source->zipPath(), $body, FILE_APPEND | LOCK_EX);
        $source->forceFill(['received' => $source->received + strlen($body)])->save();

        return response()->json(['received' => $source->received]);
    }

    #[NotAudited('Stages an upload; the deploy that uses it is recorded, naming the file')]
    public function finish(Workspace $workspace, Machine $machine, string $token, DeploySourcePacker $packer): JsonResponse
    {
        $this->authorize($workspace, $machine);
        $source = $this->uploading($workspace, $token);

        // A big project takes a while to unpack and commit.
        set_time_limit(600);

        if ($source->received !== $source->size) {
            throw ValidationException::withMessages(['file' => 'The upload didn’t finish. Try again.']);
        }

        try {
            $packer->pack($source);
        } catch (ValidationException $e) {
            $source->forceFill(['status' => 'failed'])->save();
            File::deleteDirectory($source->directory());

            throw $e;
        }

        return response()->json([
            ...$source->detection,
            'repo' => $source->repoUrl(),
            'branch' => $source->filename,
            'commit' => $source->commit,
            'upload' => ['filename' => $source->filename, 'files' => $source->files, 'expiresAt' => $source->expires_at->toIso8601String()],
        ]);
    }

    private function authorize(Workspace $workspace, Machine $machine): void
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);
    }

    private function uploading(Workspace $workspace, string $token): DeploySource
    {
        $source = DeploySource::query()
            ->where('workspace_id', $workspace->id)
            ->where('token', $token)
            ->where('status', 'uploading')
            ->first();
        abort_if($source === null || $source->expires_at->isPast(), 404);

        return $source;
    }
}
