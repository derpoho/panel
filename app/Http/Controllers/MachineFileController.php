<?php

namespace App\Http\Controllers;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\ConsoleLock;
use App\Support\DaemonJobQueue;
use App\Support\Files\FileJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The Files tab on machines and services: browse folders, read, edit,
 * upload and delete files, as daemon jobs the page waits on. Looking
 * around needs "manage machines"; opening a file or changing anything
 * also needs two-factor and the console unlocked, since files hold
 * secrets and can change anything.
 */
class MachineFileController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue, ConsoleLock $console): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'op' => ['required', Rule::in(array_keys(FileJobs::OPS))],
            'path' => ['required', 'string', 'max:4096', 'starts_with:/', 'not_regex:#(^|/)\.\.?(/|$)#', 'not_regex:/[\x00\n\r]/'],
            'content' => ['required_if:op,write', 'nullable', 'string', 'max:'.(int) ceil(FileJobs::MAX_WRITE_BYTES * 4 / 3 + 4)],
        ], [
            'path.not_regex' => 'Use a full path, without . or .. in it.',
            'content.max' => 'Files over '.(FileJobs::MAX_WRITE_BYTES / 1024).' KB can’t be saved from the panel yet.',
        ]);

        if ($data['op'] !== 'list' && $console->requiresSetup($request->user())) {
            return response()->json(['error' => 'Turn on two-factor authentication to open and change files.', 'code' => 'two_factor_required'], 403);
        }
        if ($data['op'] !== 'list' && ! $console->isUnlocked($request->user())) {
            return response()->json(['error' => 'Unlock with a two-factor code first.', 'code' => 'console_locked'], 423);
        }

        $path = $data['path'] === '/' ? '/' : rtrim($data['path'], '/');
        [$kind, $type] = FileJobs::OPS[$data['op']];
        $job = ['type' => $type, 'path' => $path];

        if ($data['op'] === 'write') {
            $bytes = base64_decode((string) $data['content'], true);
            if ($bytes === false) {
                return response()->json(['errors' => ['content' => ['That file couldn’t be read.']]], 422);
            }
            if (strlen($bytes) > FileJobs::MAX_WRITE_BYTES) {
                return response()->json(['errors' => ['content' => ['Files over '.(FileJobs::MAX_WRITE_BYTES / 1024).' KB can’t be saved from the panel yet.']]], 422);
            }
            $job['content_b64'] = $data['content'];
        }

        if (($reason = $queue->unavailable($machine)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), $kind, $job, null, 60);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }

    /**
     * Where a file job is, and what it found. Only the person who asked
     * sees it, and a file's content only the first time.
     */
    public function show(Request $request, Workspace $workspace, Machine $machine, string $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $record = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('user_id', $request->user()->id)
            ->whereIn('kind', FileJobs::KINDS)
            ->whereKey($job)
            ->firstOrFail();

        $finished = $record->finished_at !== null;

        return response()->json([
            'state' => $record->isStale() ? 'timed_out' : $record->state,
            'result' => $finished && $record->state === 'succeeded' ? FileJobs::deliver($record) : null,
            'error' => $record->error === null ? null : ['message' => self::explain($record)],
        ]);
    }

    /** The daemon's refusal, in the panel's words where it has them. */
    private static function explain(DaemonJob $job): string
    {
        $message = (string) ($job->error['message'] ?? 'The machine couldn’t do that.');
        $path = (string) ($job->command['job']['path'] ?? '');

        return preg_match('/outside (the )?(permitted|directories ServerOS may reach)/', $message) === 1
            ? "ServerOS can’t reach {$path}. It can only open the folders of services it runs or has adopted."
            : $message;
    }
}
