<?php

namespace App\Mcp\Tools\Workflow;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\ReleaseSpec;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowText;

/**
 * What a workflow can be made of, in the words the editor uses
 * (resources/js/components/workflows/kinds.ts and StepSettings.vue) and
 * with the rules WorkflowSteps and WorkflowEngine apply: the triggers and
 * their filters, each step's ports and settings, the branch conditions
 * and the placeholders. Ports, events and conditions come from
 * WorkflowSteps itself, so they can't drift.
 */
final class WorkflowReference
{
    /** Triggers about a machine: steps may say machine_id "@trigger". */
    public const MACHINE_EVENTS = ['deploy.finished', 'service.down', 'service.crash_loop', 'machine.offline', 'machine.disk_full', 'backup.failed'];

    /** Triggers about a service, by key: restart/start/stop/command/branch may say service "@trigger". */
    public const SERVICE_EVENTS = ['service.down', 'service.crash_loop'];

    /** Triggers about a deployed app, by name: redeploy may say service "@trigger". */
    public const DEPLOY_EVENTS = ['deploy.finished'];

    /** Steps that work on a machine. */
    public const MACHINE_STEPS = ['redeploy', 'release', 'restart', 'start', 'stop', 'command', 'agent', 'backup'];

    /** event => [label, description], as the trigger's "Starts when" menu says them. */
    public const EVENT_WORDS = [
        'manual' => ['Run by hand', 'Starts when someone presses Run.'],
        'schedule' => ['On a schedule', 'Every hour, day or week.'],
        'webhook' => ['Webhook', 'When something POSTs to its URL, like your CI.'],
        'git.push' => ['Push to GitHub', 'When someone pushes to a branch.'],
        'deploy.finished' => ['A deploy finishes', 'After a deploy succeeds or fails.'],
        'service.down' => ['A service stops', 'When a running service stops on its own.'],
        'service.crash_loop' => ['A service keeps restarting', 'Three restarts within 15 minutes.'],
        'monitor.down' => ['A monitor goes down', 'After two failed checks in a row.'],
        'monitor.up' => ['A monitor is back up', 'The first passing check after it was down.'],
        'machine.offline' => ['A machine stops reporting', 'Quiet for five minutes.'],
        'machine.disk_full' => ['A disk is nearly full', 'Reaches 90%.'],
        'backup.failed' => ['A backup fails', 'Any backup that doesn’t finish.'],
    ];

    /** kind => [what it does, the title and description a new step gets]. */
    public const STEP_WORDS = [
        'trigger' => ['What starts the workflow.', 'Run it yourself', 'Starts when you press Run.'],
        'redeploy' => ['Deploys an app again: the commit a push brought, or its last deploy.', 'Redeploy the app', 'Rebuilds it, with the pushed code after a push.'],
        'release' => ['Releases an app that runs on the machine, like a Laravel app, from GitHub.', 'Release the app', 'Checks out the commit and runs its steps.'],
        'restart' => ['Restarts a service ServerOS manages.', 'Restart a service', 'Stops it and starts it again.'],
        'start' => ['Starts a stopped service.', 'Start a service', 'Starts it if it isn’t running.'],
        'stop' => ['Stops a service.', 'Stop a service', 'Stops it until something starts it.'],
        'command' => ['Runs a shell command inside a container.', 'Run a command', 'Inside a container, like a migration.'],
        'agent' => ['Runs a coding agent (Claude Code or Codex) in a container on the machine; it can open a pull request.', 'Run an agent', 'Looks into it, and opens a pull request.'],
        'backup' => ['Backs up a machine to its destination now.', 'Back up a machine', 'Every service ServerOS manages on it.'],
        'copy_data' => ['Copies the data into an environment’s databases from the one it was made from.', 'Copy data into Staging', 'Replaces its databases’ data with fresh data.'],
        'promote' => ['Deploys an environment’s apps at the code their copies run.', 'Promote to Production', 'Same code, each app’s own settings.'],
        'check_monitor' => ['Waits until a monitor passes, or gives up.', 'Check the site is up', 'Keeps checking for up to 5 minutes.'],
        'http' => ['Sends a request to any public URL.', 'Call a URL', 'Slack, Discord, your own API.'],
        'alert' => ['Sends to the workspace’s alert channels.', 'Tell the team', 'To your alert email and webhook.'],
        'wait' => ['Pauses the run for a while.', 'Wait 2 minutes', 'Pauses before the next step.'],
        'branch' => ['Takes one path or the other.', 'Check something', 'Goes left when true, right when false.'],
    ];

    /** condition => label, as the If step's "Check" menu says them. */
    public const CONDITION_WORDS = [
        'trigger_field' => 'A field the trigger sent',
        'monitor_up' => 'A monitor is up',
        'service_running' => 'A service is running',
        'disk_below' => 'A disk is below a level',
        'time_between' => 'The time is between',
    ];

    public const PLACEHOLDERS = [
        '{{workflow}}' => 'The workflow’s name.',
        '{{trigger}}' => 'What started the run, in words (“shop stopped”, “Run by Sam”, “On schedule”).',
        '{{machine}}' => 'The machine the trigger was about (empty if none).',
        '{{service}}' => 'The service or app the trigger was about (empty if none).',
        '{{run_url}}' => 'A link to this run in the dashboard.',
        '{{data.<field>}}' => 'Any field the trigger carried, by dot path: {{data.ref}} and {{data.commit}} from a webhook’s JSON, {{data.outcome}} of a deploy, {{data.cause}} of a stop. Unknown fields become empty.',
        '{{agent.summary}}' => 'What the last agent step that finished said it found and did (empty if none has).',
        '{{agent.pr}}' => 'The pull request that agent opened (empty if none).',
    ];

    public static function label(string $event): string
    {
        return self::EVENT_WORDS[$event][0] ?? $event;
    }

    /**
     * The rule names each kind uses (and, for a trigger or an If, each
     * event or condition).
     *
     * @param  array<mixed>  $rules
     * @return list<string>
     */
    public static function ruleNames(string $kind, array $rules = []): array
    {
        return match ($kind) {
            'trigger' => ['event', ...array_keys(self::eventFilters((string) ($rules['event'] ?? 'manual')))],
            'branch' => ['condition', ...array_keys(self::conditionRules((string) ($rules['condition'] ?? '')))],
            default => array_keys(self::stepRules($kind)),
        };
    }

    /**
     * Everything get_workflow_reference returns, apart from the
     * workspace's own choices.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'graph' => [
                'shape' => '{"nodes": [node, ...], "edges": [edge, ...]}. update_workflow replaces the whole graph, so send every node and edge you want to keep (get_workflow returns the current graph in this shape).',
                'node' => [
                    'id' => 'Your choice: letters, digits, “-” and “_”, up to 64, unique in the graph. Edges refer to it.',
                    'kind' => 'One of: '.implode(', ', array_keys(WorkflowSteps::PORTS)).'.',
                    'title' => 'What the step is called on the canvas (up to 80 characters). Left out: the kind’s default, as the editor gives a new step.',
                    'description' => 'A line under the title (up to 280). Left out: the kind’s default.',
                    'rules' => 'The step’s settings, as listed under kinds: names of lowercase letters and “_”, values that are strings, numbers, booleans or null (no lists or objects). Strings up to 300 characters; body, command and message up to 2000; an agent’s prompt up to '.AgentSpec::MAX_PROMPT.'.',
                    'x, y' => 'Where it sits on the canvas, in pixels (the node’s left edge and top; nodes are 400 wide). Optional: nodes without both are laid out under the trigger, 200 apart.',
                ],
                'edge' => [
                    'source' => 'The id of the step it leaves.',
                    'port' => 'Which way out of the source: one of the source kind’s ports (default “out”).',
                    'target' => 'The id of the step it leads to. Never the trigger, never the source itself.',
                    'id' => 'Optional; made up if left out.',
                ],
                'howRunsGo' => [
                    'A run starts at the trigger and follows the edges out of each step by how it ended: “out” when it worked, “failed” when it didn’t, “true” or “false” from an If.',
                    'Several edges from one port run their targets one after another, all in the same run. Each step runs at most once per run, so an edge back to an earlier step does nothing.',
                    'A step that fails with nothing on its “failed” port fails the run (an alert to the team when it wasn’t run by hand); with something there, the run carries on that way.',
                    'Steps nothing leads to never run.',
                    'Machine work (restart, deploy, command, agent, backup, copy_data, promote) runs as whoever last saved the workflow, with their permissions; their save makes it so.',
                ],
            ],
            'events' => array_map(fn (string $event) => [
                'event' => $event,
                'label' => self::EVENT_WORDS[$event][0],
                'description' => self::EVENT_WORDS[$event][1],
                'filters' => (object) self::eventFilters($event),
                'carries' => self::eventData($event),
                'machineFromTrigger' => in_array($event, self::MACHINE_EVENTS, true),
                'serviceFromTrigger' => match (true) {
                    in_array($event, self::SERVICE_EVENTS, true) => 'key',
                    in_array($event, self::DEPLOY_EVENTS, true) => 'deploy',
                    default => null,
                },
            ], WorkflowSteps::EVENTS),
            'kinds' => array_map(fn (string $kind) => [
                'kind' => $kind,
                'label' => WorkflowText::kindLabel($kind),
                'does' => self::STEP_WORDS[$kind][0],
                'ports' => WorkflowSteps::PORTS[$kind],
                'rules' => (object) ($kind === 'trigger'
                    ? ['event' => self::rule('string', 'What starts the workflow; its filters are listed under events.', true, WorkflowSteps::EVENTS, 'manual')]
                    : ($kind === 'branch'
                        ? ['condition' => self::rule('string', 'What to check; the rest of its rules depend on it (see conditions).', true, WorkflowSteps::CONDITIONS)]
                        : self::stepRules($kind))),
                'note' => self::stepNote($kind),
            ], array_keys(WorkflowSteps::PORTS)),
            'conditions' => array_map(fn (string $condition) => [
                'condition' => $condition,
                'label' => self::CONDITION_WORDS[$condition],
                'rules' => (object) self::conditionRules($condition),
                'true' => self::conditionTrue($condition),
            ], WorkflowSteps::CONDITIONS),
            'specialValues' => [
                WorkflowSteps::FROM_TRIGGER => 'In machine_id: the machine the trigger was about (triggers with machineFromTrigger). In service: the service from the trigger, by key for restart/start/stop/command/If (service.down, service.crash_loop), or the deployed app’s name for redeploy (deploy.finished). A run by hand pretends the trigger was about its filtered machine and service, or the first machine.',
                WorkflowSteps::ALL_MACHINES => 'In a backup step’s machine_id only: every machine in the workspace. Machines that are offline or have nothing to back up are skipped and noted; the step fails only if none could start, or one of them fails.',
            ],
            'placeholders' => [
                'values' => self::PLACEHOLDERS,
                'usedIn' => 'An alert’s subject and message, an HTTP request’s url (URL-encoded) and body, a command, and an agent’s prompt.',
            ],
            'limits' => [
                'nodes' => WorkflowSteps::MAX_NODES,
                'edges' => WorkflowSteps::MAX_EDGES,
                'stepsPerRun' => WorkflowEngine::MAX_STEPS,
                'workflowsPerWorkspace' => 100,
                'runsPerHour' => Workflow::RUNS_PER_HOUR,
                'secondsBetweenEventRuns' => Workflow::COOLDOWN_SECONDS,
                'serverJobMinutes' => WorkflowEngine::JOB_TIMEOUT_MINUTES,
                'alertEmails' => WorkflowSteps::MAX_EMAILS,
                'runsKeptDays' => WorkflowRun::KEEP_DAYS,
                'note' => 'Event triggers don’t fire for a machine in maintenance or a muted monitor, and one workflow starts from events at most once every '.Workflow::COOLDOWN_SECONDS.' seconds, so a step that restarts what the trigger watches can’t loop.',
            ],
            'turningOn' => 'A workflow runs on its own only when enabled, and it can be enabled (or run by hand) only when get_workflow lists no problems. Workflows need the Pro or Business plan. Saving one with a command or agent step needs two-factor authentication on, and through MCP a connection allowed to run commands.',
            'example' => self::example(),
        ];
    }

    /**
     * A small, complete graph: restart a service that stopped, check it
     * stayed up, and tell the team if not.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function example(): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger', 'kind' => 'trigger', 'title' => 'When an app stops', 'rules' => ['event' => 'service.down']],
                ['id' => 'start', 'kind' => 'start', 'title' => 'Start it again', 'rules' => ['machine_id' => WorkflowSteps::FROM_TRIGGER, 'service' => WorkflowSteps::FROM_TRIGGER]],
                ['id' => 'wait', 'kind' => 'wait', 'title' => 'Give it two minutes', 'rules' => ['minutes' => 2]],
                ['id' => 'still_up', 'kind' => 'branch', 'title' => 'Is it still running?', 'rules' => ['condition' => 'service_running', 'machine_id' => WorkflowSteps::FROM_TRIGGER, 'service' => WorkflowSteps::FROM_TRIGGER]],
                ['id' => 'tell', 'kind' => 'alert', 'title' => 'Tell the team', 'rules' => ['subject' => '{{service}} won’t stay up on {{machine}}', 'message' => 'Restarting didn’t help. {{run_url}}', 'tone' => 'bad']],
            ],
            'edges' => [
                ['source' => 'trigger', 'port' => 'out', 'target' => 'start'],
                ['source' => 'start', 'port' => 'out', 'target' => 'wait'],
                ['source' => 'start', 'port' => 'failed', 'target' => 'tell'],
                ['source' => 'wait', 'port' => 'out', 'target' => 'still_up'],
                ['source' => 'still_up', 'port' => 'false', 'target' => 'tell'],
            ],
        ];
    }

    /**
     * The trigger's filters for an event: an empty filter means "any".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function eventFilters(string $event): array
    {
        $machine = self::rule('integer', 'Only this machine (its id). Leave out for any machine.');

        return match ($event) {
            'schedule' => [
                'every' => self::rule('string', 'How often.', true, ['hour', 'day', 'week']),
                'at' => self::rule('string', '“HH:MM”, UTC. Every hour: only the minutes count (“00:15” is a quarter past each hour).', false, null, '03:00'),
                'weekday' => self::rule('integer', 'Every week: 1 is Monday … 7 is Sunday.', false, [1, 2, 3, 4, 5, 6, 7], 1),
            ],
            'git.push' => [
                'repo' => self::rule('string', 'The repository, as owner/name. Its owner must be connected to the workspace through ServerOS’s GitHub App (Settings → General); pushes arrive from there.', true),
                'branch' => self::rule('string', 'Only pushes to this branch.', false, null, 'main'),
            ],
            'deploy.finished' => [
                'machine_id' => $machine,
                'service' => self::rule('string', 'Only deploys of this app, by its deploy name (choices.machines[].deploys). Leave out for any.'),
                'outcome' => self::rule('string', 'Which deploys.', false, ['any', 'succeeded', 'failed'], 'any'),
            ],
            'service.down', 'service.crash_loop' => [
                'machine_id' => $machine,
                'service' => self::rule('string', 'Only this service: its key (like docker:4f2a… or systemd:nginx.service) or its name. Leave out for any.'),
            ],
            'monitor.down', 'monitor.up' => [
                'monitor_id' => self::rule('integer', 'Only this monitor (its id). Leave out for any.'),
            ],
            'machine.offline', 'machine.disk_full', 'backup.failed' => [
                'machine_id' => $machine,
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private static function eventData(string $event): array
    {
        return match ($event) {
            'manual' => ['test (true)', 'machine_id', 'machine', 'service_key', 'service: a run by hand pretends to be about the first machine and service'],
            'schedule' => [],
            'webhook' => ['Whatever JSON was POSTed (its top-level fields, up to 50): {{data.ref}}, say. The URL is secret: it’s shown on the workflow in the dashboard, made when a webhook workflow is first saved or created.'],
            'git.push' => ['repo (owner/name)', 'branch', 'commit (the full SHA pushed)', 'message (its first line)', 'pusher', 'url'],
            'deploy.finished' => ['machine_id', 'machine', 'service (the app’s deploy name)', 'outcome (succeeded or failed)', 'job_id'],
            'service.down', 'service.crash_loop' => ['machine_id', 'machine', 'service_key', 'service (its name)', 'cause'],
            'monitor.down', 'monitor.up' => ['monitor_id', 'monitor', 'cause'],
            'machine.offline', 'machine.disk_full' => ['machine_id', 'machine'],
            'backup.failed' => ['machine_id', 'machine', 'service', 'cause'],
            default => [],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function stepRules(string $kind): array
    {
        $machine = fn (bool $all = false) => self::rule('string|integer', 'The machine: its id'
            .', or “@trigger” for the machine the trigger was about (triggers: '.implode(', ', self::MACHINE_EVENTS).')'
            .($all ? ', or “@all” for every machine' : '').'.', true);
        $service = fn (string $which) => self::rule('string', $which, true);

        return match ($kind) {
            'redeploy' => [
                'machine_id' => $machine(),
                'service' => $service('The app to redeploy, by its deploy name (choices.machines[].deploys: it must have been deployed to that machine once), or “@trigger” with a deploy.finished trigger. Its last deploy runs again (the last that worked, if any). When a Push to GitHub trigger for the app’s own repository started the run, the pushed commit is deployed instead, with the same domain and settings.'),
            ],
            'release' => [
                'machine_id' => $machine(),
                'service' => $service('The managed service the app runs under (a systemd: key from choices.machines[].services[], like systemd:php8.4-fpm.service). The folder must be one ServerOS looks after because of it.'),
                'path' => self::rule('string', 'The app’s folder on the machine, an existing git checkout, like /var/www/app.', true),
                'user' => self::rule('string', 'Who the checkout and commands run as. Never root.', false, null, 'www-data'),
                'repo' => self::rule('string', 'The GitHub repository, as owner/name.', true),
                'branch' => self::rule('string', 'Released when the trigger didn’t push this repository and branch: its latest commit.', false, null, 'main'),
                'steps' => self::rule('string', 'The commands, as a JSON list of {"run": "composer install …", "again": true}, up to '.ReleaseSpec::MAX_STEPS.', run in order in the folder after the checkout. again: run it once more on the old commit when a failed release is put back (true for installs and caches, false for migrations).'),
                'mode' => self::rule('string', 'How it goes live. atomic: built in its own folder beside the app (<path>-releases/), then the app’s folder, a link from the first atomic release on, switches to it in one step; a failed build changes nothing, a failed reload or health check switches straight back, and a run’s release can be rolled back to the one before it. in_place: the checkout is updated where it is, and a failure checks the last commit out again.', false, ReleaseSpec::MODES, 'in_place'),
                'reload' => self::rule('string', 'Managed services to reload once it’s live, one per line or comma separated: PHP-FPM, so it serves the new code.'),
                'restart' => self::rule('string', 'Managed services to restart once it’s live, one per line or comma separated: queue workers and the scheduler, so they run the new code.'),
                'shared' => self::rule('string', 'Atomic: paths inside the app every release shares rather than ships, one per line.', false, null, ".env\nstorage"),
                'trim' => self::rule('string', 'Atomic: paths removed from kept releases that aren’t live, to save disk (node_modules once assets are built). Empty for none.'),
                'keep' => self::rule('integer', 'Atomic: how many releases to keep for rolling back to.', false, null, 3, 1, ReleaseSpec::MAX_KEEP),
                'health_url' => self::rule('string', 'An https:// address that must answer 2xx after the release, or the last commit goes back.'),
                'discard_changes' => self::rule('boolean', 'Throw away edits made on the machine to tracked files. Off, such edits stop the release.', false, null, false),
            ],
            'restart', 'start', 'stop' => [
                'machine_id' => $machine(),
                'service' => $service('The service’s key (choices.machines[].services[].key, like docker:4f2a… or systemd:nginx.service), which must be managed by ServerOS; or “@trigger” with a service.down or service.crash_loop trigger.'),
            ],
            'command' => [
                'machine_id' => $machine(),
                'service' => $service('A managed container’s key (docker:…; container and managed true in choices), or “@trigger” with a service.down or service.crash_loop trigger.'),
                'command' => self::rule('string', 'The shell command, up to 2000 characters, placeholders allowed. Runs inside the container, as whoever last saved the workflow, for up to 300 seconds; the step fails (leaves by failed) if the command exits with anything but 0, or the machine reports it failed.', true),
            ],
            'agent' => [
                'machine_id' => $machine(),
                'service' => $service('The container the agent runs in (a docker: key, container and managed true in choices), usually one deployed from the Coding agent template, which has Claude Code, Codex, git and Node, and the API keys as its settings.'),
                'agent' => self::rule('string', 'Which agent: claude (Claude Code, needs ANTHROPIC_API_KEY on the container) or codex (needs OPENAI_API_KEY).', false, array_keys(AgentSpec::AGENTS), 'claude'),
                'prompt' => self::rule('string', 'What the agent should do, up to '.AgentSpec::MAX_PROMPT.' characters, placeholders allowed. ServerOS adds what started the run (the trigger’s summary and data) and asks it to end with a summary for the team.', true),
                'repo' => self::rule('string', 'A GitHub repository to work in, as owner/name: it’s cloned fresh for the run (through the workspace’s GitHub App, or the container’s GH_TOKEN). Empty for none.'),
                'branch' => self::rule('string', 'The branch to check out, and the pull request’s base.', false, null, 'main'),
                'pull_request' => self::rule('boolean', 'Push what the agent changed to a new branch (serveros/agent-<run>-<step>) and open a pull request with its summary. Needs repo. The agent never sees the token; nothing is merged.', false, null, false),
                'pr_title' => self::rule('string', 'The pull request’s title. Empty: “<workflow>: changes from the agent”.'),
                'model' => self::rule('string', 'The model, passed to the agent as is (sonnet, opus or haiku for Claude Code; gpt-5-codex for Codex). Empty: the agent’s default.'),
                'setup' => self::rule('string', 'A shell command run in the checkout before the agent starts, like npm ci. The step fails if it does. Needs repo.'),
                'check' => self::rule('string', 'A shell command that must pass after the agent’s change, like npm test. The agent is asked to make it pass; ServerOS runs it again and opens no pull request if it fails. Needs repo.'),
                'minutes' => self::rule('integer', 'How long the agent may work before it’s stopped.', false, null, AgentSpec::DEFAULT_MINUTES, AgentSpec::MIN_MINUTES, AgentSpec::MAX_MINUTES),
            ],
            'backup' => [
                'machine_id' => $machine(true),
            ],
            'copy_data' => [
                'project' => self::rule('integer', 'The project (its id, choices.projects[].id).', true),
                'environment' => self::rule('integer', 'The environment to copy data into (choices.projects[].environments[].id): a copy, one whose source is set. Its PostgreSQL, MySQL, MariaDB and MongoDB databases are filled from the environment it was made from, replacing what’s there; nothing is ever written to the source.', true),
            ],
            'promote' => [
                'project' => self::rule('integer', 'The project (its id, choices.projects[].id).', true),
                'from' => self::rule('integer', 'The environment whose apps’ code goes out, like Staging (choices.projects[].environments[].id).', true),
                'to' => self::rule('integer', 'The environment whose apps get it, like Production. Each app there that has a copy in from is deployed at that copy’s code, keeping its own settings, domain and data.', true),
            ],
            'check_monitor' => [
                'monitor_id' => self::rule('integer', 'The monitor to check (its id).', true),
                'timeout' => self::rule('integer', 'Minutes to keep checking (every 30 seconds) before it fails. The dashboard offers 1, 2, 5, 10, 15 and 30.', false, null, 5, 1, 30),
            ],
            'http' => [
                'method' => self::rule('string', 'The method.', false, ['GET', 'POST', 'PUT'], 'POST'),
                'url' => self::rule('string', 'A public http(s) URL, placeholders allowed (URL-encoded). Private and internal addresses are refused when it runs; redirects aren’t followed; it waits 15 seconds.', true),
                'body' => self::rule('string', 'What to send (not with GET), up to 2000 characters, placeholders allowed: sent as JSON if it is valid JSON, as text otherwise. Empty: {"workflow", "run", "trigger"} as JSON.'),
            ],
            'alert' => [
                'subject' => self::rule('string', 'What the alert says, placeholders allowed.', true),
                'message' => self::rule('string', 'More detail, up to 2000 characters, placeholders allowed.'),
                'tone' => self::rule('string', 'A problem, or good news.', false, ['bad', 'good'], 'bad'),
                'to' => self::rule('string', 'The team (the workspace’s alert email and webhook, as alert settings say), specific people, or both.', false, WorkflowSteps::ALERT_TO, 'team'),
                'emails' => self::rule('string', 'With to “emails” or “both”: up to '.WorkflowSteps::MAX_EMAILS.' addresses, separated by commas.'),
            ],
            'wait' => [
                'minutes' => self::rule('integer', 'How long to pause. The dashboard offers 1, 2, 5, 10, 15, 30, 60, 120, 360, 720 and 1440.', true, null, 2, 1, 1440),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function conditionRules(string $condition): array
    {
        $machine = self::rule('string|integer', 'The machine: its id, or “@trigger” for the machine the trigger was about.', true);

        return match ($condition) {
            'trigger_field' => [
                'field' => self::rule('string', 'A field the trigger carried, by dot path (“ref”, “outcome”, “payload.branch”).', true),
                'value' => self::rule('string', 'What it must equal, compared as text exactly.'),
            ],
            'monitor_up' => [
                'monitor_id' => self::rule('integer', 'The monitor (its id).', true),
            ],
            'service_running' => [
                'machine_id' => $machine,
                'service' => self::rule('string', 'A service key from the machine’s scan (managed or not), or “@trigger” with a service.down or service.crash_loop trigger.', true),
            ],
            'disk_below' => [
                'machine_id' => $machine,
                'percent' => self::rule('integer', 'The level. The dashboard offers 50, 70, 80, 85, 90 and 95.', false, null, 85),
            ],
            'time_between' => [
                'from' => self::rule('string', '“HH:MM”, UTC.', false, null, '09:00'),
                'to' => self::rule('string', '“HH:MM”, UTC. Earlier than from means across midnight.', false, null, '17:00'),
            ],
            default => [],
        };
    }

    private static function conditionTrue(string $condition): string
    {
        return match ($condition) {
            'trigger_field' => 'The field equals value.',
            'monitor_up' => 'The monitor’s status is up.',
            'service_running' => 'The service is running in the machine’s latest scan.',
            'disk_below' => 'The machine’s fullest disk is under percent.',
            'time_between' => 'Now (UTC) is from or later and before to.',
            default => '',
        };
    }

    private static function stepNote(string $kind): ?string
    {
        return match ($kind) {
            'trigger' => 'Exactly one per workflow; nothing can lead into it.',
            'command' => 'Saving a workflow with a command needs two-factor authentication on, and a connection allowed to run commands.',
            'agent' => 'Like a command step it needs two-factor authentication on. Leaves by “failed” when the agent can’t start (no API key, not installed), errors, runs out of time, or its pull request can’t be opened. Later steps can say {{agent.summary}} and {{agent.pr}}.',
            'release' => 'Like a command step it needs two-factor authentication on. Leaves by “failed” when any command, the reload or the health check fails; the machine has then put the last commit back.',
            'check_monitor' => 'Runs the monitor’s check now, then every 30 seconds until it passes (out) or the time is up (failed). A paused monitor fails it.',
            'copy_data' => 'Waits for every copy that started, up to 70 minutes; leaves by “failed” if one fails or none could start (the machines aren’t on the project’s network yet, say). Databases still waiting are copied when they’re ready.',
            'promote' => 'Waits for every deploy; leaves by “failed” if one fails its health check (the live version keeps running). With nothing to promote it leaves by “out” at once.',
            'alert' => 'Never fails unless emailing the specific people fails.',
            'wait' => 'The run picks up again within a minute of the time.',
            'branch' => 'Never fails: it leaves by “true” or “false”.',
            default => null,
        };
    }

    /**
     * @param  list<mixed>|null  $values
     * @return array<string, mixed>
     */
    private static function rule(string $type, string $description, bool $required = false, ?array $values = null, mixed $default = null, ?int $min = null, ?int $max = null): array
    {
        return array_filter([
            'type' => $type,
            'required' => $required,
            'values' => $values,
            'default' => $default,
            'min' => $min,
            'max' => $max,
            'description' => $description,
        ], fn ($v) => $v !== null);
    }
}
