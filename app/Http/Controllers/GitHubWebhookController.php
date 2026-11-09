<?php

namespace App\Http\Controllers;

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Workflow;
use App\Support\GitHub\GitHubApp;
use App\Support\Projects\Previews;
use App\Support\Workflows\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Deliveries from the ServerOS GitHub App. A push to a repository it's
 * installed on starts each "Push to GitHub" workflow, in the workspaces
 * that connected that installation, watching that repository and branch.
 * A pull request on it opens, updates or closes its preview environment
 * in projects previewing that repository.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, WorkflowEngine $engine, Previews $previews): JsonResponse
    {
        if (! GitHubApp::signed($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['error' => 'Bad signature.'], 401);
        }
        if ($request->header('X-GitHub-Event') === 'pull_request') {
            return $this->pullRequest($request, $previews);
        }
        if ($request->header('X-GitHub-Event') !== 'push') {
            return response()->json(['ignored' => true]);
        }

        $installation = (int) $request->input('installation.id');
        $repo = (string) $request->input('repository.full_name');
        $ref = (string) $request->input('ref');
        $commit = (string) $request->input('after');
        if ($installation === 0 || $repo === '' || ! str_starts_with($ref, 'refs/heads/')
            || $request->boolean('deleted') || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            return response()->json(['ignored' => true]);
        }
        $branch = substr($ref, strlen('refs/heads/'));
        $message = (string) str((string) $request->input('head_commit.message'))->before("\n")->limit(120);
        $data = [
            'repo' => $repo,
            'branch' => $branch,
            'commit' => $commit,
            'message' => $message,
            'pusher' => (string) $request->input('pusher.name'),
            'url' => (string) $request->input('head_commit.url'),
        ];

        $workspaces = GitHubInstallation::query()->where('installation_id', $installation)->pluck('workspace_id');
        $workflows = Workflow::query()
            ->with('workspace')
            ->whereIn('workspace_id', $workspaces)
            ->where('enabled', true)
            ->where('trigger', 'git.push')
            ->get()
            ->filter(function (Workflow $workflow) use ($repo, $branch) {
                $rules = $workflow->triggerRules();

                return strcasecmp(trim((string) ($rules['repo'] ?? '')), $repo) === 0
                    && (($rules['branch'] ?? '') ?: 'main') === $branch;
            });

        $started = 0;
        foreach ($workflows as $workflow) {
            $summary = "Push to {$branch} of {$repo}: ".substr($commit, 0, 7).($message !== '' ? " {$message}" : '');
            if ($engine->start($workflow, 'git.push', $summary, $data) !== null) {
                $started++;
            }
        }

        return response()->json(['started' => $started], 202);
    }

    /**
     * A pull request opened, got new commits, or closed: its preview
     * environment in each project previewing that repository follows it.
     * Pull requests from forks never get one.
     */
    private function pullRequest(Request $request, Previews $previews): JsonResponse
    {
        $installation = (int) $request->input('installation.id');
        $repo = strtolower((string) $request->input('repository.full_name'));
        $action = (string) $request->input('action');
        $number = (int) $request->input('pull_request.number');
        $head = (string) $request->input('pull_request.head.sha');
        $fromFork = strtolower((string) $request->input('pull_request.head.repo.full_name')) !== $repo;
        if ($installation === 0 || $repo === '' || $number < 1 || preg_match('/^[0-9a-f]{40}$/', $head) !== 1
            || ! in_array($action, ['opened', 'reopened', 'synchronize', 'closed'], true)) {
            return response()->json(['ignored' => true]);
        }
        if ($fromFork && $action !== 'closed') {
            return response()->json(['ignored' => 'fork']);
        }

        $workspaces = GitHubInstallation::query()->where('installation_id', $installation)->pluck('workspace_id');
        $projects = Project::query()
            ->with('workspace')
            ->whereIn('workspace_id', $workspaces)
            ->whereNotNull('previews')
            ->get()
            // Closing always counts, even with previews since turned off.
            ->filter(fn (Project $project) => strtolower(Previews::settings($project)['repo']) === $repo
                && ($action === 'closed' || Previews::settings($project)['enabled']));

        foreach ($projects as $project) {
            try {
                match ($action) {
                    'opened', 'reopened' => $previews->opened($project, $number, $head),
                    'synchronize' => $previews->updated($project, $number, $head),
                    'closed' => $previews->closed($project, $number),
                };
            } catch (Throwable $e) {
                // One project's trouble doesn't stop the others.
                report($e);
            }
        }

        return response()->json(['projects' => $projects->count()], 202);
    }
}
