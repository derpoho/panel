<?php

namespace App\Support\Workflows;

use App\Models\DaemonJob;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use App\Support\Brand;
use App\Support\GitHub\GitHubApp;
use RuntimeException;

/**
 * A "Run agent" step: a coding agent (Claude Code or Codex) run headless
 * inside a container on the person's own machine, usually the Coding
 * agent template. It reads the prompt and what the trigger was about,
 * works in a fresh checkout of a repository when there is one, and
 * ServerOS pushes what it changed to a new branch and opens a pull
 * request. The agent never sees the GitHub token: it's taken out of the
 * agent's environment and only used to clone, push and open the PR.
 *
 * It runs as a service_exec job (up to an hour on the daemon), with the
 * script, prompt and token handed in as settings by name, so none of them
 * show in the job's log or the machine's process list.
 */
final class AgentSpec
{
    public const AGENTS = ['claude' => 'Claude Code', 'codex' => 'Codex'];

    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 60;

    public const DEFAULT_MINUTES = 20;

    public const MAX_PROMPT = 4000;

    /** What the script prints before the agent's own summary. */
    public const SUMMARY = '::serveros-summary::';

    public const PR = '::serveros-pr::';

    public const ERROR = '::serveros-error::';

    public const NOTE = '::serveros-note::';

    /** The model that actually ran, as the agent reported it. */
    public const MODEL = '::serveros-model::';

    public static function minutes(mixed $value): int
    {
        $minutes = (int) ($value ?: self::DEFAULT_MINUTES);

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));
    }

    /**
     * What's wrong with an agent step's settings, past the machine and
     * service, in plain words; null when it can run.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function problem(array $rules, ?string $event = null): ?string
    {
        $repo = trim((string) ($rules['repo'] ?? ''));
        $fromTrigger = $repo === WorkflowSteps::FROM_TRIGGER;

        return match (true) {
            $fromTrigger && $event !== 'deploy.finished' => 'Only a “deploy finishes” trigger has a repository to work in; enter one as owner/name.',
            ! isset(self::AGENTS[(string) (($rules['agent'] ?? '') ?: 'claude')]) => 'Choose Claude Code or Codex.',
            trim((string) ($rules['prompt'] ?? '')) === '' => 'Write what the agent should do.',
            $repo !== '' && ! $fromTrigger && ! self::templated($repo) && ReleaseSpec::github($repo) === null => 'Enter the repository, as owner/name.',
            $repo !== '' && ! self::templated((string) ($rules['branch'] ?? '')) && ! ReleaseSpec::validBranch((string) (($rules['branch'] ?? '') ?: 'main')) => 'That isn’t a branch name.',
            $repo === '' && (bool) ($rules['pull_request'] ?? false) => 'Choose a repository for the pull request.',
            ($rules['model'] ?? '') !== '' && preg_match('#^[A-Za-z0-9._:/\[\]-]{1,80}$#', (string) $rules['model']) !== 1 => 'That isn’t a model name, like opus or gpt-5-codex.',
            $repo === '' && trim((string) ($rules['setup'] ?? '').($rules['check'] ?? '')) !== '' => 'Setup and check commands run in the repository: choose one.',
            ($rules['minutes'] ?? '') !== '' && ((int) $rules['minutes'] < self::MIN_MINUTES || (int) $rules['minutes'] > self::MAX_MINUTES) => 'Give it between '.self::MIN_MINUTES.' and '.self::MAX_MINUTES.' minutes.',
            default => null,
        };
    }

    /** A setting filled in from the trigger when the step runs: {{data.repository.full_name}}. */
    public static function templated(string $value): bool
    {
        return str_contains($value, '{{');
    }

    /**
     * The branch to work on, with any placeholders filled in.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function branch(WorkflowRun $run, array $rules): string
    {
        $branch = trim(WorkflowText::render((string) ($rules['branch'] ?? ''), $run));

        return $branch === '' ? 'main' : $branch;
    }

    /**
     * The service_exec job: the script to run, and the settings it reads.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws RuntimeException when a repository or branch filled in from
     *                          the trigger isn't one
     */
    public static function job(Workspace $workspace, WorkflowRun $run, string $node, string $service, array $rules): array
    {
        $agent = (string) (($rules['agent'] ?? '') ?: 'claude');
        if (trim((string) ($rules['repo'] ?? '')) !== '' && self::repository($run, $rules) === null) {
            throw new RuntimeException(trim((string) $rules['repo']) === WorkflowSteps::FROM_TRIGGER
                ? 'The deployed app isn’t built from a GitHub repository, so there’s nothing to work in.'
                : 'The repository the trigger gave isn’t owner/name on GitHub.');
        }
        if (! ReleaseSpec::validBranch(self::branch($run, $rules))) {
            throw new RuntimeException('The branch the trigger gave isn’t a branch name.');
        }
        $minutes = self::minutes($rules['minutes'] ?? null);
        $env = [
            'SERVEROS_AGENT_SCRIPT' => self::script(),
            'SERVEROS_AGENT_KIND' => $agent,
            'SERVEROS_AGENT_PROMPT' => self::prompt($run, $rules),
            'SERVEROS_AGENT_MINUTES' => (string) $minutes,
            'SERVEROS_AGENT_RUN' => mb_substr("{$run->workflow->name}, run #{$run->id}", 0, 120),
            'SERVEROS_AGENT_TRIGGER' => mb_substr((string) ($run->trigger['summary'] ?? ''), 0, 200),
            'SERVEROS_AGENT_ASK' => mb_substr(trim(WorkflowText::render((string) ($rules['prompt'] ?? ''), $run)), 0, 3000),
            'SERVEROS_AGENT_RUN_URL' => 'https://'.$run->workflow->workspace->host()."/workflows/{$run->workflow->id}?run={$run->id}",
        ];
        foreach (['model' => 'SERVEROS_AGENT_MODEL', 'setup' => 'SERVEROS_AGENT_SETUP', 'check' => 'SERVEROS_AGENT_CHECK'] as $rule => $name) {
            $value = trim((string) ($rules[$rule] ?? ''));
            if ($value !== '') {
                $env[$name] = $value;
            }
        }

        // The workspace's own key, from Settings › AI and MCP; without one the
        // container's own ANTHROPIC_API_KEY or OPENAI_API_KEY is used.
        $own = app(AiProviders::class)->key($workspace, $agent === 'codex' ? 'openai' : 'anthropic');
        if ($own !== null) {
            $env[$agent === 'codex' ? 'OPENAI_API_KEY' : 'ANTHROPIC_API_KEY'] = $own->key;
        }

        $repo = self::repository($run, $rules);
        if ($repo !== null) {
            [$owner, $name] = $repo;
            $env['SERVEROS_AGENT_REPO'] = "{$owner}/{$name}";
            $env['SERVEROS_AGENT_BRANCH'] = self::branch($run, $rules);
            if ((bool) ($rules['pull_request'] ?? false)) {
                $env['SERVEROS_AGENT_PR_BRANCH'] = "serveros/agent-{$run->id}-".substr(preg_replace('/[^a-z0-9-]/', '', strtolower($node)) ?: 'run', 0, 24);
                $env['SERVEROS_AGENT_TITLE'] = self::title($run, $rules);
            }
            // Read or write, as the step needs; none when the workspace
            // hasn't connected the owner (a public repository still clones,
            // and the container's own GH_TOKEN is the fallback for pushing).
            $github = app(GitHubApp::class);
            $write = isset($env['SERVEROS_AGENT_PR_BRANCH']) ? $github->writeTokenFor($workspace, $owner, $name) : null;
            $token = $write ?? $github->tokenFor($workspace, $owner, $name);
            // Pushed with the app's own token: the commits are the app's too.
            if ($write !== null && ($bot = $github->botIdentity()) !== null) {
                $env['SERVEROS_AGENT_AUTHOR_NAME'] = $bot['name'];
                $env['SERVEROS_AGENT_AUTHOR_EMAIL'] = $bot['email'];
            }
            if ($token !== null) {
                $env['SERVEROS_GH_TOKEN'] = $token;
            }
        }

        return [
            'type' => 'service_exec',
            'service' => $service,
            'command' => 'exec sh -c "$SERVEROS_AGENT_SCRIPT" serveros-agent',
            'timeout_secs' => $minutes * 60,
            'env' => $env,
        ];
    }

    /**
     * The prompt the agent gets: what the step asks, then what started the
     * run, then how to work.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function prompt(WorkflowRun $run, array $rules): string
    {
        $asked = trim(WorkflowText::render((string) ($rules['prompt'] ?? ''), $run));
        $data = $run->trigger['data'] ?? [];
        $details = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $repo = self::repository($run, $rules);
        $branch = self::branch($run, $rules);
        $job = self::triggerJob($run);

        $lines = [
            $asked,
            '',
            '---',
            'Context from ServerOS. A workflow started this run; nobody is watching it live, so don’t ask questions: decide, and say what you assumed.',
            '- Workflow: '.$run->workflow->name,
            '- Started by: '.($run->trigger['summary'] ?? 'someone'),
        ];
        if (filled($data['machine'] ?? null)) {
            $lines[] = '- Machine: '.$data['machine'];
        }
        if (filled($data['service'] ?? null)) {
            $lines[] = '- Service: '.$data['service'];
        }
        if ($data !== []) {
            $lines[] = '- What the trigger carried: '.mb_substr($details, 0, 3000);
        }
        if ($job !== null) {
            $error = is_array($job->error) ? trim((string) ($job->error['message'] ?? '')) : '';
            $tail = array_slice(array_values(array_filter($job->log ?? [], 'is_string')), -80);
            $lines[] = '';
            $lines[] = "The {$job->kind} job the trigger was about ended {$job->state}".($error !== '' ? ": {$error}" : '.');
            if ($tail !== []) {
                $lines[] = 'The end of its log:';
                $lines[] = '```';
                $lines[] = mb_substr(implode("\n", $tail), -6000);
                $lines[] = '```';
            }
        }
        $lines[] = '';
        if ($repo === null) {
            $lines[] = 'There is no repository checked out. Work in the current folder if you need files.';
        } elseif ((bool) ($rules['pull_request'] ?? false)) {
            $lines[] = "You’re in a fresh checkout of {$repo[0]}/{$repo[1]} at {$branch}. Make your change here; you can commit it or leave it uncommitted. Don’t push: once you finish, ServerOS pushes a branch and opens a pull request with your summary as its description. Keep the change small and focused on the task.";
        } else {
            $lines[] = "You’re in a fresh checkout of {$repo[0]}/{$repo[1]} at {$branch}, to read. Nothing you change here is kept.";
        }
        $check = trim((string) ($rules['check'] ?? ''));
        if ($repo !== null && $check !== '') {
            $lines[] = "Before you finish, run `{$check}` and make it pass. ServerOS runs it again after you, and opens no pull request if it fails.";
        }
        $lines[] = 'Finish with a short summary for the team, in plain words and markdown, without a title heading: what you found, what you did, and anything a person should check. It becomes the pull request’s description, so leave out that you didn’t commit or push.';

        return mb_substr(implode("\n", $lines), 0, self::MAX_PROMPT + 12000);
    }

    /**
     * The repository to work in: the step's, or with “@trigger” the one
     * the deployed app is built from.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: string, 1: string}|null
     */
    public static function repository(WorkflowRun $run, array $rules): ?array
    {
        $repo = trim((string) ($rules['repo'] ?? ''));
        if ($repo === WorkflowSteps::FROM_TRIGGER) {
            $spec = self::triggerJob($run)?->command['job'] ?? [];

            return is_array($spec) && is_string($spec['repo'] ?? null) ? ReleaseSpec::github($spec['repo']) : null;
        }

        if (self::templated($repo)) {
            $repo = trim(WorkflowText::render($repo, $run));
        }

        return $repo === '' ? null : ReleaseSpec::github($repo);
    }

    /**
     * The server job the trigger was about (a deploy that finished), when
     * it's on one of the workflow's workspace's machines.
     */
    public static function triggerJob(WorkflowRun $run): ?DaemonJob
    {
        $id = $run->trigger['data']['job_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return DaemonJob::query()
            ->whereKey($id)
            ->whereHas('machine', fn ($q) => $q->where('workspace_id', $run->workflow->workspace_id))
            ->first();
    }

    /** @param  array<string, mixed>  $rules */
    public static function title(WorkflowRun $run, array $rules): string
    {
        $title = trim(preg_replace('/\s+/', ' ', WorkflowText::render((string) ($rules['pr_title'] ?? ''), $run)) ?? '');

        return mb_substr($title !== '' ? $title : "{$run->workflow->name}: changes from the agent", 0, 120);
    }

    /**
     * What the agent's run said, from the job's log: its summary, the pull
     * request it opened, and why it failed when it did.
     *
     * @return array{summary: string, pr: string|null, error: string|null, note: string|null, model: string|null}
     */
    public static function outcome(DaemonJob $job): array
    {
        $log = array_values(array_filter($job->log ?? [], 'is_string'));
        $pr = $error = $note = $model = null;
        $summaryAt = null;
        foreach ($log as $i => $line) {
            $line = rtrim($line);
            if (str_starts_with($line, self::PR) && preg_match('#(https://github\.com/\S+/pull/\d+)#', $line, $m)) {
                $pr = $m[1];
            } elseif (str_starts_with($line, self::ERROR)) {
                $error = trim(substr($line, strlen(self::ERROR)));
            } elseif (str_starts_with($line, self::MODEL)) {
                $model = mb_substr(trim(substr($line, strlen(self::MODEL))), 0, 80) ?: null;
            } elseif (str_starts_with($line, self::NOTE)) {
                $note = trim(substr($line, strlen(self::NOTE)));
            } elseif ($line === self::SUMMARY) {
                $summaryAt = $i;
            }
        }
        $summary = $summaryAt === null ? '' : trim(implode("\n", array_filter(array_slice($log, $summaryAt + 1), fn (string $l) => ! str_starts_with($l, '::serveros-'))));

        return ['summary' => mb_substr($summary, 0, 6000), 'pr' => $pr, 'error' => $error, 'note' => $note, 'model' => $model];
    }

    /**
     * Runs in the agent's container (sh, so any image with a shell).
     * Clones the repository when there is one, runs the agent with the
     * GitHub token taken out of its environment, then pushes what changed
     * and opens the pull request with Node (which both agents need).
     */
    public static function script(): string
    {
        // Who its commits are by when it doesn't push as the GitHub App.
        return str_replace('__SERVEROS_AGENT_EMAIL__', Brand::agentEmail(), self::SCRIPT);
    }

    private const string SCRIPT = <<<'SH'
set -u
fail() { echo "::serveros-error:: $1"; exit 1; }
note() { echo "::serveros-note:: $1"; }

work=$(mktemp -d /tmp/serveros-agent.XXXXXX) || fail "Couldn't make a folder to work in."
trap 'rm -rf "$work"' EXIT
GIT_TOKEN="${SERVEROS_GH_TOKEN:-${GH_TOKEN:-}}"
export GIT_TOKEN
helper='!f() { test -n "$GIT_TOKEN" && echo username=x-access-token && echo "password=$GIT_TOKEN"; }; f'
cd "$work" || fail "Couldn't open the work folder."

if [ -n "${SERVEROS_AGENT_REPO:-}" ]; then
  command -v git >/dev/null 2>&1 || fail "git isn't installed in this container. Use the Coding agent template, or install git."
  echo "Cloning $SERVEROS_AGENT_REPO at $SERVEROS_AGENT_BRANCH"
  git -c credential.helper= -c "credential.helper=$helper" clone -q --depth 50 --branch "$SERVEROS_AGENT_BRANCH" "https://github.com/$SERVEROS_AGENT_REPO.git" repo \
    || fail "Couldn't clone $SERVEROS_AGENT_REPO at $SERVEROS_AGENT_BRANCH. If it's private, connect it with ServerOS's GitHub App, or set GH_TOKEN on the agent's service."
  cd repo || fail "Couldn't open the checkout."
fi

# The agent never sees the GitHub token, or this script.
run_agent() {
  env -u GIT_TOKEN -u SERVEROS_GH_TOKEN -u GH_TOKEN -u GITHUB_TOKEN -u SERVEROS_AGENT_SCRIPT IS_SANDBOX=1 "$@"
}

if [ -n "${SERVEROS_AGENT_SETUP:-}" ]; then
  echo "Setting up: $SERVEROS_AGENT_SETUP"
  run_agent sh -c "$SERVEROS_AGENT_SETUP" >"$work/setup.txt" 2>&1 || { tail -n 20 "$work/setup.txt"; fail "The setup command failed, so the agent didn't start."; }
fi

case "${SERVEROS_AGENT_KIND:-claude}" in
  claude)
    command -v claude >/dev/null 2>&1 || fail "Claude Code isn't installed in this container. Deploy the Coding agent template, or run: npm install -g @anthropic-ai/claude-code"
    [ -n "${ANTHROPIC_API_KEY:-}${CLAUDE_CODE_OAUTH_TOKEN:-}" ] || fail "Connect an Anthropic key in Settings › AI and MCP, or set ANTHROPIC_API_KEY on the agent's service."
    echo "Running Claude Code for up to ${SERVEROS_AGENT_MINUTES:-20} minutes"
    agent_name="Claude Code"; coauthor="Claude <noreply@anthropic.com>"
    set -- claude -p --dangerously-skip-permissions --output-format json
    [ -n "${SERVEROS_AGENT_MODEL:-}" ] && set -- "$@" --model "$SERVEROS_AGENT_MODEL"
    run_agent "$@" "$SERVEROS_AGENT_PROMPT" >"$work/out.json" 2>"$work/err.txt"
    code=$?
    # Its answer, and the model that did the work (the one with the most output).
    model=$(node -e '
const fs = require("fs"), [src, out] = process.argv.slice(1);
let j = {}; try { j = JSON.parse(fs.readFileSync(src, "utf8")); } catch { process.exit(0); }
fs.writeFileSync(out, String(j.result ?? ""));
const used = Object.entries(j.modelUsage || {}).sort((a, b) => (b[1].outputTokens || 0) - (a[1].outputTokens || 0));
if (used[0]) console.log(used[0][0]);
' "$work/out.json" "$work/out.txt" 2>/dev/null)
    [ -s "$work/out.txt" ] || cp "$work/out.json" "$work/out.txt" 2>/dev/null
    ;;
  codex)
    command -v codex >/dev/null 2>&1 || fail "Codex isn't installed in this container. Deploy the Coding agent template, or run: npm install -g @openai/codex"
    [ -n "${OPENAI_API_KEY:-}" ] || fail "Connect an OpenAI key in Settings › AI and MCP, or set OPENAI_API_KEY on the agent's service."
    echo "Running Codex for up to ${SERVEROS_AGENT_MINUTES:-20} minutes"
    agent_name="Codex"; coauthor="Codex <codex@openai.com>"
    model="${SERVEROS_AGENT_MODEL:-}"
    set -- codex exec --dangerously-bypass-approvals-and-sandbox --skip-git-repo-check --output-last-message "$work/out.txt"
    [ -n "${SERVEROS_AGENT_MODEL:-}" ] && set -- "$@" -m "$SERVEROS_AGENT_MODEL"
    run_agent "$@" "$SERVEROS_AGENT_PROMPT" >"$work/err.txt" 2>&1
    code=$?
    ;;
  *) fail "Unknown agent: ${SERVEROS_AGENT_KIND}" ;;
esac
[ -n "${model:-}" ] || model="${SERVEROS_AGENT_MODEL:-its default model}"
echo "::serveros-model:: $model"
if [ "$code" -ne 0 ]; then
  tail -n 20 "$work/err.txt" 2>/dev/null
  fail "The agent stopped with exit code $code."
fi

if [ -n "${SERVEROS_AGENT_PR_BRANCH:-}" ]; then
  if [ -n "$(git status --porcelain)" ]; then
    git add -A && git -c user.name="${SERVEROS_AGENT_AUTHOR_NAME:-ServerOS agent}" -c user.email="${SERVEROS_AGENT_AUTHOR_EMAIL:-__SERVEROS_AGENT_EMAIL__}" commit -q -m "$SERVEROS_AGENT_TITLE" -m "Made by $agent_name ($model) in a ServerOS workflow: ${SERVEROS_AGENT_RUN:-a workflow run}." -m "Co-Authored-By: $coauthor" \
      || fail "Couldn't commit the agent's changes."
  fi
  if [ -n "${SERVEROS_AGENT_CHECK:-}" ] && { [ -n "$(git status --porcelain)" ] || [ "$(git rev-list --count "origin/$SERVEROS_AGENT_BRANCH..HEAD" 2>/dev/null || echo 0)" -gt 0 ]; }; then
    echo "Checking: $SERVEROS_AGENT_CHECK"
    run_agent sh -c "$SERVEROS_AGENT_CHECK" >"$work/check.txt" 2>&1 || {
      tail -n 30 "$work/check.txt"
      echo "::serveros-summary::"; head -c 6000 "$work/out.txt"; echo
      fail "The check failed after the agent's change, so no pull request was opened."
    }
    checked="$SERVEROS_AGENT_CHECK"
  fi
  ahead=$(git rev-list --count "origin/$SERVEROS_AGENT_BRANCH..HEAD" 2>/dev/null || echo 0)
  if [ "$ahead" -gt 0 ]; then
    [ -n "$GIT_TOKEN" ] || fail "The agent changed the code, but there's no GitHub token to push it with. Let ServerOS's GitHub App write to the repository, or set GH_TOKEN on the agent's service."
    git -c credential.helper= -c "credential.helper=$helper" push -q origin "HEAD:refs/heads/$SERVEROS_AGENT_PR_BRANCH" \
      || fail "Couldn't push $SERVEROS_AGENT_PR_BRANCH. The token may not be allowed to write to the repository."
    command -v node >/dev/null 2>&1 || fail "Pushed $SERVEROS_AGENT_PR_BRANCH, but Node isn't here to open the pull request."
    stat=$(git diff --shortstat "origin/$SERVEROS_AGENT_BRANCH..HEAD" 2>/dev/null | sed 's/^ *//')
    pr=$(SERVEROS_AGENT_BODY="$work/out.txt" SERVEROS_AGENT_NAME="$agent_name" SERVEROS_AGENT_MODEL_USED="$model" SERVEROS_AGENT_CHECKED="${checked:-}" SERVEROS_AGENT_STAT="$stat" node -e '
const [repo, head, base, title] = process.argv.slice(1);
const e = process.env;
const esc = (v) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const url = /^https:\/\//.test(e.SERVEROS_AGENT_RUN_URL || "") ? e.SERVEROS_AGENT_RUN_URL : "";
const run = url ? `<a href="${esc(url)}">${esc(e.SERVEROS_AGENT_RUN || "the run")}</a>` : esc(e.SERVEROS_AGENT_RUN || "A workflow run");
const summary = require("fs").readFileSync(e.SERVEROS_AGENT_BODY, "utf8").trim().slice(0, 50000);
const rows = [
  ["Agent", `${esc(e.SERVEROS_AGENT_NAME)} &nbsp;<code>${esc(e.SERVEROS_AGENT_MODEL_USED)}</code>`],
  ["Workflow", run],
  e.SERVEROS_AGENT_TRIGGER ? ["Started by", esc(e.SERVEROS_AGENT_TRIGGER)] : null,
  e.SERVEROS_AGENT_CHECKED ? ["Check", `Passed &nbsp;<code>${esc(e.SERVEROS_AGENT_CHECKED)}</code>`] : null,
  e.SERVEROS_AGENT_STAT ? ["Changes", esc(e.SERVEROS_AGENT_STAT)] : null,
].filter(Boolean);
const ask = (e.SERVEROS_AGENT_ASK || "").trim();
const body = [
  `<b>ServerOS</b> &nbsp;<code>coding agent</code> &nbsp;·&nbsp; ${esc(e.SERVEROS_AGENT_NAME)} &nbsp;<code>${esc(e.SERVEROS_AGENT_MODEL_USED)}</code>`,
  "",
  summary || "_The agent didn’t write a summary._",
  "",
  "<details><summary>Run details</summary>\n\n<table>" + rows.map(([k, v]) => `<tr><td><b>${k}</b></td><td>${v}</td></tr>`).join("") + "</table>\n\n"
    + (ask ? "<b>What it was asked</b>\n\n~~~text\n" + ask.replace(/~~~/g, "~ ~ ~") + "\n~~~\n" : "") + "\n</details>",
  "",
  `<sub>Made on your own machine by <a href="https://serveros.com">ServerOS</a>. Nothing is merged without you.` + (url ? ` · <a href="${esc(url)}">Open the run</a>` : "") + "</sub>",
].join("\n");
fetch((process.env.SERVEROS_GITHUB_API || "https://api.github.com") + "/repos/" + repo + "/pulls", {
  method: "POST",
  headers: { authorization: "Bearer " + process.env.GIT_TOKEN, accept: "application/vnd.github+json", "user-agent": "ServerOS-Agent" },
  body: JSON.stringify({ title, head, base, body }),
}).then(async (r) => {
  const j = await r.json().catch(() => ({}));
  if (!r.ok) { console.error(j.message || ("GitHub answered " + r.status)); process.exit(1); }
  console.log(j.html_url);
}).catch((e) => { console.error(String(e)); process.exit(1); });
' "$SERVEROS_AGENT_REPO" "$SERVEROS_AGENT_PR_BRANCH" "$SERVEROS_AGENT_BRANCH" "$SERVEROS_AGENT_TITLE" 2>"$work/pr.txt") \
      || fail "Pushed $SERVEROS_AGENT_PR_BRANCH, but couldn't open the pull request: $(head -c 300 "$work/pr.txt")"
    echo "::serveros-pr:: $pr"
  else
    note "The agent didn't change any code, so there's no pull request."
  fi
fi

echo "::serveros-summary::"
head -c 6000 "$work/out.txt"
echo
SH;
}
