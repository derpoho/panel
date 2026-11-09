import { trans } from 'laravel-vue-i18n';
import {
    SolarBell as Bell,
    SolarGitBranch as GitBranch,
    SolarArchive as Archive,
    SolarClock as Clock,
    SolarGlobe as Globe,
    SolarHeartPulse as HeartPulse,
    SolarRocket as Rocket,
    SolarFolderGit2 as FolderGit,
    SolarTerminal as Terminal,
    SolarZap as Zap,
    SolarPlay as Play,
    SolarRefreshCw as RefreshCw,
    SolarSquare as Square,
    SolarDatabaseBackup as DatabaseBackup,
    SolarCircleArrowUp as CircleArrowUp,
    SolarSparkles as Sparkles,
} from '@/lib/solarIcons';
import type { Component } from 'vue';

/**
 * What a workflow is made of; app/Support/Workflows/WorkflowSteps.php
 * keeps the same list on the server.
 */
export type StepKind =
    | 'trigger'
    | 'redeploy'
    | 'release'
    | 'restart'
    | 'start'
    | 'stop'
    | 'command'
    | 'agent'
    | 'backup'
    | 'copy_data'
    | 'promote'
    | 'check_monitor'
    | 'http'
    | 'alert'
    | 'wait'
    | 'branch';

export type PortId = 'out' | 'failed' | 'true' | 'false';

export type Rules = Record<string, string | number | boolean | null>;

export type WorkflowNode = {
    id: string;
    kind: StepKind;
    x: number;
    y: number;
    title: string;
    description: string;
    rules: Rules;
    fresh?: boolean;
};

export type WorkflowEdge = {
    id: string;
    source: string;
    port: PortId;
    target: string;
};

export type Graph = { nodes: WorkflowNode[]; edges: WorkflowEdge[] };

export type StepDefinition = {
    kind: StepKind;
    label: string;
    /** For dark canvases, and a deeper one for light. */
    accent: string;
    ink: string;
    icon: Component;
    title: string;
    description: string;
    ports: PortId[];
    /** One line for the palette's tooltip and the empty Build tab. */
    help: string;
};

export const STEPS: Record<StepKind, StepDefinition> = {
    trigger: {
        kind: 'trigger',
        label: trans('Trigger'),
        accent: '#9875ff',
        ink: '#6d44f0',
        icon: Zap,
        title: trans('Run it yourself'),
        description: trans('Starts when you press Run.'),
        ports: ['out'],
        help: trans('What starts the workflow.'),
    },
    redeploy: {
        kind: 'redeploy',
        label: trans('Redeploy'),
        accent: '#75caff',
        ink: '#0a7bc2',
        icon: Rocket,
        title: trans('Redeploy the app'),
        description: trans('Rebuilds it, with the pushed code after a push.'),
        ports: ['out', 'failed'],
        help: trans(
            'Deploys an app again: the commit a push brought, or its last deploy.',
        ),
    },
    release: {
        kind: 'release',
        label: trans('Release app'),
        accent: '#75caff',
        ink: '#0a7bc2',
        icon: FolderGit,
        title: trans('Release the app'),
        description: trans('Checks out the commit and runs its steps.'),
        ports: ['out', 'failed'],
        help: trans(
            'Releases an app that runs on the machine, like a Laravel app, from GitHub.',
        ),
    },
    restart: {
        kind: 'restart',
        label: trans('Restart service'),
        accent: '#75ff77',
        ink: '#16a34a',
        icon: RefreshCw,
        title: trans('Restart a service'),
        description: trans('Stops it and starts it again.'),
        ports: ['out', 'failed'],
        help: trans('Restarts a service ServerOS manages.'),
    },
    start: {
        kind: 'start',
        label: trans('Start service'),
        accent: '#75ff77',
        ink: '#16a34a',
        icon: Play,
        title: trans('Start a service'),
        description: trans('Starts it if it isn’t running.'),
        ports: ['out', 'failed'],
        help: trans('Starts a stopped service.'),
    },
    stop: {
        kind: 'stop',
        label: trans('Stop service'),
        accent: '#ff7575',
        ink: '#dc2626',
        icon: Square,
        title: trans('Stop a service'),
        description: trans('Stops it until something starts it.'),
        ports: ['out', 'failed'],
        help: trans('Stops a service.'),
    },
    command: {
        kind: 'command',
        label: trans('Run command'),
        accent: '#ffb575',
        ink: '#c2410c',
        icon: Terminal,
        title: trans('Run a command'),
        description: trans('Inside a container, like a migration.'),
        ports: ['out', 'failed'],
        help: trans('Runs a shell command inside a container.'),
    },
    agent: {
        kind: 'agent',
        label: trans('Run agent'),
        accent: '#d8a6ff',
        ink: '#9333ea',
        icon: Sparkles,
        title: trans('Run an agent'),
        description: trans('Looks into it, and opens a pull request.'),
        ports: ['out', 'failed'],
        help: trans(
            'Runs Claude Code or Codex in a container on your machine. It can open a pull request.',
        ),
    },
    backup: {
        kind: 'backup',
        label: trans('Back up'),
        accent: '#75aaff',
        ink: '#2563eb',
        icon: Archive,
        title: trans('Back up a machine'),
        description: trans('Every service ServerOS manages on it.'),
        ports: ['out', 'failed'],
        help: trans('Backs up a machine to its destination now.'),
    },
    copy_data: {
        kind: 'copy_data',
        label: trans('Copy data'),
        accent: '#75aaff',
        ink: '#2563eb',
        icon: DatabaseBackup,
        title: trans('Copy data into Staging'),
        description: trans('Replaces its databases’ data with fresh data.'),
        ports: ['out', 'failed'],
        help: trans(
            'Copies the data into an environment’s databases from the one it was made from.',
        ),
    },
    promote: {
        kind: 'promote',
        label: trans('Promote'),
        accent: '#75caff',
        ink: '#0a7bc2',
        icon: CircleArrowUp,
        title: trans('Promote to Production'),
        description: trans('Same code, each app’s own settings.'),
        ports: ['out', 'failed'],
        help: trans(
            'Deploys an environment’s apps at the code their copies run.',
        ),
    },
    check_monitor: {
        kind: 'check_monitor',
        label: trans('Check monitor'),
        accent: '#75ffd3',
        ink: '#0d9488',
        icon: HeartPulse,
        title: trans('Check the site is up'),
        description: trans('Keeps checking for up to 5 minutes.'),
        ports: ['out', 'failed'],
        help: trans('Waits until a monitor passes, or gives up.'),
    },
    http: {
        kind: 'http',
        label: trans('HTTP request'),
        accent: '#ffb575',
        ink: '#c2410c',
        icon: Globe,
        title: trans('Call a URL'),
        description: trans('Slack, Discord, your own API.'),
        ports: ['out', 'failed'],
        help: trans('Sends a request to any public URL.'),
    },
    alert: {
        kind: 'alert',
        label: trans('Send alert'),
        accent: '#ff75e3',
        ink: '#c026d3',
        icon: Bell,
        title: trans('Tell the team'),
        description: trans('To your alert email and webhook.'),
        ports: ['out'],
        help: trans('Sends to the workspace’s alert channels.'),
    },
    wait: {
        kind: 'wait',
        label: trans('Wait'),
        accent: '#ff7575',
        ink: '#dc2626',
        icon: Clock,
        title: trans('Wait 2 minutes'),
        description: trans('Pauses before the next step.'),
        ports: ['out'],
        help: trans('Pauses the run for a while.'),
    },
    branch: {
        kind: 'branch',
        label: trans('If'),
        accent: '#75ffd3',
        ink: '#0d9488',
        icon: GitBranch,
        title: trans('Check something'),
        description: trans('Goes left when true, right when false.'),
        ports: ['true', 'false'],
        help: trans('Takes one path or the other.'),
    },
};

export const STEP_GROUPS: { label: string; kinds: StepKind[] }[] = [
    {
        label: trans('Services'),
        kinds: ['redeploy', 'release', 'restart', 'start', 'stop', 'command'],
    },
    { label: trans('Agents'), kinds: ['agent'] },
    { label: trans('Data'), kinds: ['backup'] },
    { label: trans('Environments'), kinds: ['copy_data', 'promote'] },
    { label: trans('Checks'), kinds: ['check_monitor', 'http'] },
    { label: trans('Tell people'), kinds: ['alert'] },
    { label: trans('Flow control'), kinds: ['wait', 'branch'] },
];

export const PORT_LABELS: Partial<Record<PortId, string>> = {
    true: 'TRUE',
    false: 'FALSE',
    failed: 'FAILED',
};

export type EventId =
    | 'manual'
    | 'schedule'
    | 'webhook'
    | 'git.push'
    | 'deploy.finished'
    | 'service.down'
    | 'service.crash_loop'
    | 'monitor.down'
    | 'monitor.up'
    | 'machine.offline'
    | 'machine.disk_full'
    | 'backup.failed';

export const EVENTS: {
    id: EventId;
    label: string;
    description: string;
    /** Which filters the trigger offers. */
    filters: ('machine' | 'service' | 'deploy' | 'monitor' | 'outcome')[];
    /** It's about a machine and a service, so steps can say "the one from the trigger". */
    machine?: boolean;
    service?: 'key' | 'deploy';
}[] = [
    {
        id: 'manual',
        label: trans('Run by hand'),
        description: trans('Starts when someone presses Run.'),
        filters: [],
    },
    {
        id: 'schedule',
        label: trans('On a schedule'),
        description: trans('Every hour, day or week.'),
        filters: [],
    },
    {
        id: 'webhook',
        label: trans('Webhook'),
        description: trans('When something POSTs to its URL, like your CI.'),
        filters: [],
    },
    {
        id: 'git.push',
        label: trans('Push to GitHub'),
        description: trans('When someone pushes to a branch.'),
        filters: [],
    },
    {
        id: 'deploy.finished',
        label: trans('A deploy finishes'),
        description: trans('After a deploy succeeds or fails.'),
        filters: ['machine', 'deploy', 'outcome'],
        machine: true,
        service: 'deploy',
    },
    {
        id: 'service.down',
        label: trans('A service stops'),
        description: trans('When a running service stops on its own.'),
        filters: ['machine', 'service'],
        machine: true,
        service: 'key',
    },
    {
        id: 'service.crash_loop',
        label: trans('A service keeps restarting'),
        description: trans('Three restarts within 15 minutes.'),
        filters: ['machine', 'service'],
        machine: true,
        service: 'key',
    },
    {
        id: 'monitor.down',
        label: trans('A monitor goes down'),
        description: trans('After two failed checks in a row.'),
        filters: ['monitor'],
    },
    {
        id: 'monitor.up',
        label: trans('A monitor is back up'),
        description: trans('The first passing check after it was down.'),
        filters: ['monitor'],
    },
    {
        id: 'machine.offline',
        label: trans('A machine stops reporting'),
        description: trans('Quiet for five minutes.'),
        filters: ['machine'],
        machine: true,
    },
    {
        id: 'machine.disk_full',
        label: trans('A disk is nearly full'),
        description: trans('Reaches 90%.'),
        filters: ['machine'],
        machine: true,
    },
    {
        id: 'backup.failed',
        label: trans('A backup fails'),
        description: trans('Any backup that doesn’t finish.'),
        filters: ['machine'],
        machine: true,
    },
];

export const CONDITIONS: { id: string; label: string }[] = [
    { id: 'trigger_field', label: trans('A field the trigger sent') },
    { id: 'monitor_up', label: trans('A monitor is up') },
    { id: 'service_running', label: trans('A service is running') },
    { id: 'disk_below', label: trans('A disk is below a level') },
    { id: 'time_between', label: trans('The time is between') },
];

export const FROM_TRIGGER = '@trigger';

/** One command a release runs, and whether it runs again when a failed release is put back. */
export type ReleaseCommand = { run: string; again: boolean };

/** What a Laravel app needs; app/Support/Workflows/ReleaseSpec.php has the same. */
export const LARAVEL_STEPS: ReleaseCommand[] = [
    {
        run: 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader',
        again: true,
    },
    { run: 'php artisan storage:link', again: true },
    { run: 'npm ci && npm run build', again: true },
    { run: 'php artisan migrate --force', again: false },
    { run: 'php artisan optimize', again: true },
    { run: 'php artisan queue:restart', again: true },
];

/** Every machine in the workspace (backups only). */
export const ALL_MACHINES = '@all';

export type MachineOption = {
    id: number;
    name: string;
    services: {
        key: string;
        name: string;
        managed: boolean;
        container: boolean;
    }[];
    deploys: string[];
};

export type ProjectOption = {
    id: number;
    name: string;
    /** source: the environment it was copied from, null for the first. */
    environments: { id: number; name: string; source: number | null }[];
};

export type Options = {
    machines: MachineOption[];
    monitors: { id: number; name: string }[];
    projects?: ProjectOption[];
};

export type RunStep = {
    state:
        | 'pending'
        | 'running'
        | 'waiting'
        | 'succeeded'
        | 'failed'
        | 'cancelled';
    startedAt?: number;
    finishedAt?: number;
    message?: string;
    port?: PortId;
    edges?: string[];
    unhandled?: boolean;
    /** A release step: the commit it replaced, still kept, and whether it's been switched back to. */
    rollbackTo?: string;
    rolledBack?: boolean;
    /** An agent step: what it said, and the pull request it opened. */
    summary?: string;
    pr?: string | null;
};

export type RunLog = {
    t: number;
    node: string | null;
    level: 'info' | 'success' | 'warning' | 'error';
    message: string;
};

export type Run = {
    id: number;
    state: 'running' | 'succeeded' | 'failed' | 'cancelled';
    trigger: { kind: string; summary: string };
    user: string | null;
    steps: Record<string, RunStep>;
    logs: RunLog[];
    startedAt: string;
    finishedAt: string | null;
    seconds: number;
    graph: Graph;
};

/** A project's name, and one of its environments', from the editor's options. */
export function projectName(options: Options, id: unknown): string | null {
    return (
        options.projects?.find((p) => String(p.id) === String(id))?.name ?? null
    );
}

export function environmentName(
    options: Options,
    project: unknown,
    id: unknown,
): string | null {
    return (
        options.projects
            ?.find((p) => String(p.id) === String(project))
            ?.environments.find((e) => String(e.id) === String(id))?.name ??
        null
    );
}

/** CSS variables that colour a step: its accent, and the deeper ink. */
export function stepTheme(kind: StepKind): Record<string, string> {
    return { '--accent': STEPS[kind].accent, '--accent-ink': STEPS[kind].ink };
}
