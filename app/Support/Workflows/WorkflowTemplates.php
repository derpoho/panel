<?php

namespace App\Support\Workflows;

use App\Models\DaemonJob;
use App\Models\Workspace;

/**
 * Starting points for what people most often automate, grouped by who
 * they're for, and filled in with the workspace's first machine, service
 * and monitor where they fit.
 */
final class WorkflowTemplates
{
    /** Who a template is for => how the picker labels them. */
    public const AUDIENCES = [
        'new' => 'New to servers',
        'developers' => 'Developers',
        'agencies' => 'Agencies',
        'teams' => 'Teams at scale',
    ];

    /**
     * @return list<array{key: string, name: string, description: string, audience: string, for: string}>
     */
    public static function list(): array
    {
        $templates = [
            // New to servers: keep things running without knowing how.
            ['site-down', 'new', 'Keep my website online', 'When your site stops answering, restart the app behind it, wait until it’s back, and email you either way.'],
            ['restart-crashed', 'new', 'Restart apps that crash', 'When an app stops on its own, start it again, check it stayed up, and only bother you if it didn’t.'],
            ['nightly-backup', 'new', 'Back up every night', 'Back up your machine at 3:00 every night. Quiet when it works; an email if it doesn’t.'],
            ['nightly-restart', 'new', 'Restart an app every night', 'A fresh start at 4:00 clears memory leaks and stuck processes in apps that slow down over time.'],

            // Developers: deploys, and what should happen around them.
            ['laravel-push', 'developers', 'Deploy a Laravel app on push', 'When you push to main, ServerOS builds the new release beside the live one (composer, the build, migrations, caches) and switches over in one step. A failed build changes nothing; a failed check switches straight back.'],
            ['ci-deploy', 'developers', 'Deploy when CI passes', 'GitHub Actions (or any CI) calls a webhook after tests pass; ServerOS redeploys main, checks the site and tells the team.'],
            ['migrate-after-deploy', 'developers', 'Run migrations after a deploy', 'After a successful deploy, run your migrations inside the app’s container, and alert if they fail.'],
            ['smoke-test', 'developers', 'Check the site after each deploy', 'A minute after a deploy, make sure the site still answers, and say so loudly if it doesn’t.'],
            ['deploy-notify', 'developers', 'Post deploys to Slack or Discord', 'Post to a chat webhook when a deploy finishes, and alert the team if it failed.'],
            ['staging-refresh', 'developers', 'Refresh Staging every night', 'At 3:30 every night, copy Production’s data into Staging’s databases, so you test against yesterday’s real data. Tells the team if a copy fails.'],
            ['agent-fix-deploy', 'developers', 'Fix failed deploys with an agent', 'When a deploy fails, a coding agent on your own machine reads the build log and the code, and opens a pull request with a fix for you to review. Needs the Coding agent template.'],
            ['agent-issues', 'developers', 'Turn labelled issues into pull requests', 'Label a GitHub issue “agent” and a coding agent on your machine works on it and opens a pull request. Point a GitHub webhook for issues at this workflow.'],
            ['staging-promote', 'developers', 'Promote Staging when it checks out', 'Run it when Staging looks right: ServerOS checks Staging’s site answers, then deploys Production at Staging’s code and tells the team how it went.'],

            // Agencies: many clients, keep them informed.
            ['client-site-down', 'agencies', 'Tell a client their site is down', 'Restart a client’s app when their monitor goes down, then email them (and you) what happened.'],
            ['backup-all', 'agencies', 'Back up every client machine', 'Every night at 2:00, back up every machine in the workspace, skipping any that are offline.'],
            ['client-deploy-notice', 'agencies', 'Email a client after a deploy', 'When you ship to a client’s machine, email them a short note that their site was updated.'],

            // Teams at scale: on-call, hours, and stopping damage.
            ['page-oncall', 'teams', 'Page on-call when a machine goes down', 'Send a PagerDuty (or Opsgenie, or any) incident when a machine stops reporting, and alert the team.'],
            ['business-hours', 'teams', 'Fix it at night, call people by day', 'In working hours, tell the team a service stopped; out of hours, restart it first and only escalate if that fails.'],
            ['crash-loop', 'teams', 'Stop a crash loop', 'When a service keeps restarting, stop it so it can’t hammer the database or fill the logs, and tell the team.'],
        ];

        return [
            ...array_map(fn (array $t) => [
                'key' => $t[0],
                'audience' => $t[1],
                'for' => self::AUDIENCES[$t[1]],
                'name' => $t[2],
                'description' => $t[3],
            ], $templates),
            ['key' => 'blank', 'audience' => 'everyone', 'for' => 'Everyone', 'name' => 'Start from scratch', 'description' => 'An empty workflow you run by hand. Add steps in easy mode, or draw it on the canvas.'],
        ];
    }

    /**
     * @return array{name: string, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}}
     */
    public static function build(string $key, Workspace $workspace): array
    {
        $machine = $workspace->machines()->orderBy('name')->first();
        $monitor = $workspace->monitors()->orderBy('name')->first();
        $machineId = $machine?->id;
        $deploy = $machine ? DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->whereNotNull('service')->latest()->value('service') : null;
        $services = $machine?->inventory['services'] ?? [];
        $managed = collect(is_array($services) ? $services : [])->filter(fn ($s) => is_array($s) && ($s['details']['managed'] ?? null) === 'true');
        $service = $managed->first();
        $container = $managed->first(fn ($s) => str_starts_with((string) ($s['key'] ?? ''), 'docker:'));
        $all = WorkflowSteps::ALL_MACHINES;
        // The first project with a copy (Staging), and that copy.
        // A lasting copy like Staging, never a pull request's preview, which
        // goes when the pull request closes.
        $lasting = fn ($e) => $e->source_id !== null && $e->pull_request === null;
        $project = $workspace->projects()->with('environments')->orderBy('name')->get()
            ->first(fn ($p) => $p->environments->contains($lasting));
        $copy = $project?->environments->first($lasting);
        $trigger = WorkflowSteps::FROM_TRIGGER;
        // A coding agent's container, from the Coding agent template, on
        // whichever machine has one.
        [$agentMachine, $agentKey] = [$machineId, ''];
        foreach ($workspace->machines()->orderBy('name')->get() as $m) {
            $box = collect(is_array($m->inventory['services'] ?? null) ? $m->inventory['services'] : [])->first(fn ($s) => is_array($s)
                && ($s['details']['managed'] ?? null) === 'true'
                && str_starts_with((string) ($s['key'] ?? ''), 'docker:')
                && str_contains(strtolower(($s['name'] ?? '').' '.($s['key'] ?? '')), 'agent'));
            if ($box !== null) {
                [$agentMachine, $agentKey] = [$m->id, (string) $box['key']];
                break;
            }
        }

        $b = new GraphBuilder;

        switch ($key) {
            case 'site-down':
                $t = $b->node('trigger', 0, 0, 'When '.($monitor->name ?? 'my site').' goes down', 'After two failed checks in a row.', ['event' => 'monitor.down', 'monitor_id' => $monitor?->id]);
                $restart = $b->node('restart', 0, 200, 'Restart '.($service['name'] ?? 'the app'), 'The app that serves the site.', ['machine_id' => $machineId, 'service' => $service['key'] ?? ''], $t);
                $check = $b->node('check_monitor', 0, 400, 'Wait until it answers', 'Keeps checking for up to 5 minutes.', ['monitor_id' => $monitor?->id, 'timeout' => 5], $restart);
                $b->node('alert', -220, 620, 'Tell me it’s back', 'Nothing to do; just so you know.', ['subject' => 'Your site went down and is back', 'message' => 'ServerOS restarted the app and the site is answering again.', 'tone' => 'good'], $check);
                $b->node('alert', 220, 620, 'Tell me it’s still down', 'A restart wasn’t enough.', ['subject' => 'Your site is still down', 'message' => 'Restarting the app didn’t bring it back. Open the run to see what happened, or check the app’s logs.', 'tone' => 'bad'], $check, 'failed');
                $b->node('alert', 460, 400, 'Tell me the restart failed', 'The restart itself didn’t work.', ['subject' => 'Your site is down and couldn’t be restarted', 'message' => 'Open the run for the error.', 'tone' => 'bad'], $restart, 'failed');
                $name = 'Keep my website online';
                break;

            case 'restart-crashed':
                $t = $b->node('trigger', 0, 0, 'When an app stops', 'Any app on any machine that stops on its own.', ['event' => 'service.down']);
                $start = $b->node('start', 0, 200, 'Start it again', 'The same app that stopped.', ['machine_id' => $trigger, 'service' => $trigger], $t);
                $wait = $b->node('wait', 0, 400, 'Give it two minutes', 'Long enough to crash again if it’s going to.', ['minutes' => 2], $start);
                $if = $b->node('branch', 0, 580, 'Is it still running?', 'Checks the app after the wait.', ['condition' => 'service_running', 'machine_id' => $trigger, 'service' => $trigger], $wait);
                $b->node('alert', 220, 800, 'Tell me it won’t stay up', 'It stopped again after restarting.', ['subject' => '{{service}} won’t stay up on {{machine}}', 'message' => 'Restarting didn’t help; its logs will say why.', 'tone' => 'bad'], $if, 'false');
                $b->node('alert', 460, 400, 'Tell me it couldn’t start', 'The start itself failed.', ['subject' => '{{service}} couldn’t be started on {{machine}}', 'message' => 'Open the run for the error.', 'tone' => 'bad'], $start, 'failed');
                $name = 'Restart apps that crash';
                break;

            case 'nightly-backup':
                $t = $b->node('trigger', 0, 0, 'Every night at 3:00 UTC', 'When traffic is lowest.', ['event' => 'schedule', 'every' => 'day', 'at' => '03:00']);
                $backup = $b->node('backup', 0, 200, 'Back up '.($machine->name ?? 'my machine'), 'Every app and database ServerOS manages on it.', ['machine_id' => $machineId], $t);
                $b->node('alert', 220, 420, 'Tell me if it failed', 'Quiet when it works.', ['subject' => 'Last night’s backup failed', 'message' => 'Until one succeeds there’s nothing recent to restore. Open the run to see why.', 'tone' => 'bad'], $backup, 'failed');
                $name = 'Back up every night';
                break;

            case 'nightly-restart':
                $t = $b->node('trigger', 0, 0, 'Every night at 4:00 UTC', 'After the backup, before anyone’s up.', ['event' => 'schedule', 'every' => 'day', 'at' => '04:00']);
                $restart = $b->node('restart', 0, 200, 'Restart '.($service['name'] ?? 'the app'), 'Takes a few seconds.', ['machine_id' => $machineId, 'service' => $service['key'] ?? ''], $t);
                $b->node('alert', 220, 420, 'Tell me if it didn’t restart', 'Quiet when it works.', ['subject' => '{{workflow}} failed', 'message' => 'The nightly restart didn’t work. Open the run to see why.', 'tone' => 'bad'], $restart, 'failed');
                $name = 'Restart an app every night';
                break;

            case 'laravel-push':
                $fpm = $managed->first(fn ($s) => str_contains(strtolower((string) ($s['key'] ?? '')), 'php') && str_contains(strtolower((string) ($s['key'] ?? '')), 'fpm'));
                $t = $b->node('trigger', 0, 0, 'When I push to main', 'Pushes to your repository through ServerOS’s GitHub App.', ['event' => 'git.push', 'repo' => '', 'branch' => 'main']);
                $release = $b->node('release', 0, 200, 'Release the app', 'Builds it beside the live one, then switches over.', [
                    'machine_id' => $machineId,
                    'service' => $fpm['key'] ?? '',
                    'reload' => $fpm['key'] ?? '',
                    'path' => '/var/www/app',
                    'user' => ReleaseSpec::DEFAULT_USER,
                    'repo' => '',
                    'branch' => 'main',
                    'steps' => json_encode(ReleaseSpec::LARAVEL_STEPS),
                    'health_url' => '',
                    'mode' => 'atomic',
                    'shared' => implode("\n", ReleaseSpec::LARAVEL_SHARED),
                    'trim' => implode("\n", ReleaseSpec::LARAVEL_TRIM),
                    'keep' => 3,
                    'restart' => '',
                ], $t);
                $b->node('alert', -220, 420, 'Tell the team it’s live', 'To the team’s alert email and webhook.', ['subject' => 'Released {{data.commit}}', 'message' => '{{data.message}}', 'tone' => 'good'], $release);
                $b->node('alert', 220, 420, 'Release failed', 'The last commit is running again.', ['subject' => 'A release failed', 'message' => 'ServerOS put the last commit back. Open the run to see which step failed.', 'tone' => 'bad'], $release, 'failed');
                $name = 'Deploy a Laravel app on push';
                break;

            case 'ci-deploy':
                $t = $b->node('trigger', 0, 0, 'When CI calls the webhook', 'Add the webhook to your CI after the tests.', ['event' => 'webhook']);
                $if = $b->node('branch', 0, 200, 'Only the main branch', 'Pushes to other branches are ignored.', ['condition' => 'trigger_field', 'field' => 'ref', 'value' => 'refs/heads/main'], $t);
                $deployNode = $b->node('redeploy', -220, 420, 'Redeploy '.($deploy ?? 'the app'), 'Rebuilds it, with the pushed code after a push.', ['machine_id' => $machineId, 'service' => $deploy ?? ''], $if, 'true');
                $check = $b->node('check_monitor', -220, 620, 'Check the site is up', 'Keeps checking for up to 5 minutes.', ['monitor_id' => $monitor?->id, 'timeout' => 5], $deployNode);
                $b->node('alert', -220, 840, 'Tell the team it’s live', 'To the team’s alert email and webhook.', ['subject' => 'Deployed {{service}}', 'message' => 'Live and answering. {{data.commit}}', 'tone' => 'good'], $check);
                $b->node('alert', 260, 840, 'Deployed, but the site is down', 'Something in the new version broke it.', ['subject' => 'The site is down after deploying', 'message' => 'Check the logs, or redeploy the previous commit.', 'tone' => 'bad'], $check, 'failed');
                $b->node('alert', 260, 620, 'Deploy failed', 'The old version keeps running.', ['subject' => 'The deploy failed', 'message' => 'Open the run to see the build output.', 'tone' => 'bad'], $deployNode, 'failed');
                $name = 'Deploy when CI passes';
                break;

            case 'migrate-after-deploy':
                $t = $b->node('trigger', 0, 0, 'When a deploy works', ($deploy ? "Deploys of {$deploy}." : 'Any deploy that succeeds.'), ['event' => 'deploy.finished', 'outcome' => 'succeeded', 'machine_id' => $machineId, 'service' => $deploy]);
                $migrate = $b->node('command', 0, 200, 'Run the migrations', 'Change the command for your framework.', ['machine_id' => $machineId, 'service' => $container['key'] ?? '', 'command' => 'php artisan migrate --force'], $t);
                $b->node('alert', 220, 420, 'Migrations failed', 'The new code is live without its database changes.', ['subject' => 'Migrations failed after deploying {{service}}', 'message' => 'Open the run to see the output, then fix and redeploy.', 'tone' => 'bad'], $migrate, 'failed');
                $name = 'Run migrations after a deploy';
                break;

            case 'smoke-test':
                $t = $b->node('trigger', 0, 0, 'When a deploy works', 'Any deploy that succeeds.', ['event' => 'deploy.finished', 'outcome' => 'succeeded']);
                $wait = $b->node('wait', 0, 200, 'Give it a minute', 'For the app to warm up.', ['minutes' => 1], $t);
                $check = $b->node('check_monitor', 0, 380, 'Check '.($monitor->name ?? 'the site').' answers', 'Keeps checking for up to 3 minutes.', ['monitor_id' => $monitor?->id, 'timeout' => 3], $wait);
                $b->node('alert', 220, 600, 'The deploy broke the site', 'Quiet when it works.', ['subject' => '{{service}} deployed, but the site isn’t answering', 'message' => 'Redeploy the previous version, or check the logs.', 'tone' => 'bad'], $check, 'failed');
                $name = 'Check the site after each deploy';
                break;

            case 'deploy-notify':
                $t = $b->node('trigger', 0, 0, 'When a deploy finishes', 'Any app, any machine.', ['event' => 'deploy.finished', 'outcome' => 'any']);
                $if = $b->node('branch', 0, 200, 'Did it work?', 'Splits on how the deploy ended.', ['condition' => 'trigger_field', 'field' => 'outcome', 'value' => 'succeeded'], $t);
                $b->node('http', -220, 420, 'Post to the team chat', 'Paste a Slack or Discord webhook URL.', ['method' => 'POST', 'url' => '', 'body' => '{"text": "Deployed {{service}} on {{machine}}", "content": "Deployed {{service}} on {{machine}}"}'], $if, 'true');
                $b->node('alert', 220, 420, 'Alert: deploy failed', 'To the team.', ['subject' => '{{trigger}}', 'message' => 'The previous version keeps running.', 'tone' => 'bad'], $if, 'false');
                $name = 'Post deploys to chat';
                break;

            case 'agent-fix-deploy':
                $t = $b->node('trigger', 0, 0, 'When a deploy fails', 'Any app, any machine.', ['event' => 'deploy.finished', 'outcome' => 'failed']);
                $agent = $b->node('agent', 0, 200, 'Ask an agent for a fix', 'Reads the build log and the code, then opens a pull request.', [
                    'machine_id' => $agentMachine,
                    'service' => $agentKey,
                    'agent' => 'claude',
                    'repo' => $trigger,
                    'branch' => 'main',
                    'pull_request' => true,
                    'minutes' => 20,
                    'prompt' => "The deploy of {{service}} on {{machine}} failed. The end of its build log is below. Work out why it failed. If the cause is in this repository's code or build files, fix it with the smallest change that makes the build pass. If it isn't (a server problem, a missing setting or secret), change nothing and explain what a person should do.",
                ], $t);
                $b->node('alert', -220, 420, 'Tell the team', 'With the agent’s summary and its pull request.', ['subject' => 'The deploy of {{service}} failed: an agent looked into it', 'message' => "{{agent.summary}}\n\n{{agent.pr}}", 'tone' => 'bad'], $agent);
                $b->node('alert', 220, 420, 'The agent couldn’t help', 'The deploy still needs a person.', ['subject' => 'The deploy of {{service}} failed', 'message' => 'The agent couldn’t look into it. Open the run to see why. {{run_url}}', 'tone' => 'bad'], $agent, 'failed');
                $name = 'Fix failed deploys with an agent';
                break;

            case 'agent-issues':
                $t = $b->node('trigger', 0, 0, 'When GitHub sends an issue event', 'In the repository’s settings, add a webhook for Issues with this workflow’s URL, as JSON.', ['event' => 'webhook']);
                $labelled = $b->node('branch', 0, 200, 'Was a label added?', 'Ignores issues being opened, edited or closed.', ['condition' => 'trigger_field', 'field' => 'action', 'value' => 'labeled'], $t);
                $isAgent = $b->node('branch', -220, 400, 'Is the label “agent”?', 'Change it to the label you use.', ['condition' => 'trigger_field', 'field' => 'label.name', 'value' => 'agent'], $labelled, 'true');
                $agent = $b->node('agent', -440, 600, 'Work on the issue', 'Opens a pull request that closes it.', [
                    'machine_id' => $agentMachine,
                    'service' => $agentKey,
                    'agent' => 'claude',
                    'repo' => '{{data.repository.full_name}}',
                    'branch' => '{{data.repository.default_branch}}',
                    'pull_request' => true,
                    'pr_title' => 'Fix #{{data.issue.number}}: {{data.issue.title}}',
                    'minutes' => 30,
                    'prompt' => "Work on this GitHub issue and make the change it asks for.\n\n#{{data.issue.number}} {{data.issue.title}}\n\n{{data.issue.body}}\n\nKeep the change focused on the issue. End your summary with the line: Closes #{{data.issue.number}}",
                ], $isAgent, 'true');
                $b->node('alert', -660, 820, 'Tell the team there’s a pull request', 'To review like any other.', ['subject' => 'An agent worked on #{{data.issue.number}}', 'message' => "{{agent.pr}}\n\n{{agent.summary}}", 'tone' => 'good'], $agent);
                $b->node('alert', -220, 820, 'The agent got stuck', 'Nothing was pushed.', ['subject' => 'An agent couldn’t finish #{{data.issue.number}}', 'message' => 'Open the run to see why. {{run_url}}', 'tone' => 'bad'], $agent, 'failed');
                $name = 'Turn labelled issues into pull requests';
                break;

            case 'staging-refresh':
                $t = $b->node('trigger', 0, 0, 'Every night at 3:30 UTC', 'After the nightly backup.', ['event' => 'schedule', 'every' => 'day', 'at' => '03:30']);
                $copyNode = $b->node('copy_data', 0, 200, 'Copy data into '.($copy->name ?? 'Staging'), 'From '.($copy?->source->name ?? 'Production').', replacing what’s there.', ['project' => $project?->id, 'environment' => $copy?->id], $t);
                $b->node('alert', 220, 420, 'Tell the team it failed', 'Quiet when it works.', ['subject' => 'Staging’s data couldn’t be refreshed', 'message' => 'Staging still has the data from the last copy that worked. Open the run to see why.', 'tone' => 'bad'], $copyNode, 'failed');
                $name = 'Refresh Staging every night';
                break;

            case 'staging-promote':
                $t = $b->node('trigger', 0, 0, 'When Staging looks right', 'Press Run once you’ve tried it.', ['event' => 'manual']);
                $check = $b->node('check_monitor', 0, 200, 'Check Staging answers', 'Choose Staging’s monitor.', ['monitor_id' => $monitor?->id, 'timeout' => 2], $t);
                $promote = $b->node('promote', 0, 400, 'Promote to '.($copy?->source->name ?? 'Production'), 'Same code, Production’s own settings.', ['project' => $project?->id, 'from' => $copy?->id, 'to' => $copy?->source_id], $check);
                $b->node('alert', -220, 620, 'Tell the team it’s live', 'To the team’s alert email and webhook.', ['subject' => 'Promoted Staging to Production', 'message' => '{{run_url}}', 'tone' => 'good'], $promote);
                $b->node('alert', 220, 620, 'Promote failed', 'The live version keeps running.', ['subject' => 'Promoting Staging failed', 'message' => 'A deploy didn’t pass its health check. Open the run to see which.', 'tone' => 'bad'], $promote, 'failed');
                $b->node('alert', 460, 400, 'Staging isn’t answering', 'Nothing was promoted.', ['subject' => 'Staging isn’t answering, so nothing was promoted', 'message' => 'Fix Staging first, then run it again.', 'tone' => 'bad'], $check, 'failed');
                $name = 'Promote Staging when it checks out';
                break;

            case 'client-site-down':
                $t = $b->node('trigger', 0, 0, 'When '.($monitor->name ?? 'the client’s site').' goes down', 'After two failed checks in a row.', ['event' => 'monitor.down', 'monitor_id' => $monitor?->id]);
                $restart = $b->node('restart', 0, 200, 'Restart '.($service['name'] ?? 'their app'), 'The app behind their site.', ['machine_id' => $machineId, 'service' => $service['key'] ?? ''], $t);
                $check = $b->node('check_monitor', 0, 400, 'Wait until it answers', 'Up to 5 minutes.', ['monitor_id' => $monitor?->id, 'timeout' => 5], $restart);
                $b->node('alert', -220, 620, 'Tell the client it’s back', 'And your team.', ['to' => 'both', 'emails' => '', 'subject' => 'Your website had a short outage', 'message' => "Your website stopped responding for a few minutes. We restarted it automatically and it's working again; there's nothing you need to do.", 'tone' => 'good'], $check);
                $b->node('alert', 220, 620, 'Tell the client we’re on it', 'And your team.', ['to' => 'both', 'emails' => '', 'subject' => 'Your website is down; we’re on it', 'message' => "Your website stopped responding and an automatic restart didn't fix it. We've been alerted and are looking into it now.", 'tone' => 'bad'], $check, 'failed');
                $name = 'Tell a client their site is down';
                break;

            case 'backup-all':
                $t = $b->node('trigger', 0, 0, 'Every night at 2:00 UTC', 'Before anyone’s up.', ['event' => 'schedule', 'every' => 'day', 'at' => '02:00']);
                $backup = $b->node('backup', 0, 200, 'Back up every machine', 'Machines that are offline are skipped and noted.', ['machine_id' => $all], $t);
                $b->node('alert', 220, 420, 'Tell the team what failed', 'Quiet when everything worked.', ['subject' => 'Some backups failed last night', 'message' => 'Open the run to see which servers and why.', 'tone' => 'bad'], $backup, 'failed');
                $name = 'Back up every client machine';
                break;

            case 'client-deploy-notice':
                $t = $b->node('trigger', 0, 0, 'When a deploy works on '.($machine->name ?? 'the client’s machine'), 'Only successful deploys.', ['event' => 'deploy.finished', 'outcome' => 'succeeded', 'machine_id' => $machineId]);
                $b->node('alert', 0, 200, 'Email the client', 'Just them, not the team.', ['to' => 'emails', 'emails' => '', 'subject' => 'We’ve updated your website', 'message' => "We've just released an update to your website. Everything is live; let us know if anything looks off.", 'tone' => 'good'], $t);
                $name = 'Email a client after a deploy';
                break;

            case 'page-oncall':
                $t = $b->node('trigger', 0, 0, 'When a machine stops reporting', 'Any machine in the workspace.', ['event' => 'machine.offline']);
                $page = $b->node('http', -220, 200, 'Open a PagerDuty incident', 'Put your Events API routing key in the body.', ['method' => 'POST', 'url' => 'https://events.pagerduty.com/v2/enqueue', 'body' => '{"routing_key": "YOUR_ROUTING_KEY", "event_action": "trigger", "dedup_key": "serveros-{{machine}}", "payload": {"summary": "{{machine}} stopped reporting", "source": "{{machine}}", "severity": "critical"}}'], $t);
                $b->node('alert', 220, 200, 'Alert the team', 'Email and the workspace webhook.', ['subject' => '{{machine}} stopped reporting', 'message' => 'On-call has been paged. {{run_url}}', 'tone' => 'bad'], $t);
                $b->node('alert', -220, 420, 'Paging failed', 'Check the routing key.', ['subject' => 'Couldn’t page on-call about {{machine}}', 'message' => 'The PagerDuty request failed, so nobody was paged. Open the run for the response.', 'tone' => 'bad'], $page, 'failed');
                $name = 'Page on-call when a machine goes down';
                break;

            case 'business-hours':
                $t = $b->node('trigger', 0, 0, 'When a service stops', 'Any service on any machine.', ['event' => 'service.down']);
                $if = $b->node('branch', 0, 200, 'Is it working hours?', '09:00–18:00 UTC; change it to yours.', ['condition' => 'time_between', 'from' => '09:00', 'to' => '18:00'], $t);
                $b->node('alert', -260, 420, 'Tell the team now', 'People are around to look.', ['subject' => '{{service}} stopped on {{machine}}', 'message' => 'Someone take a look. {{run_url}}', 'tone' => 'bad'], $if, 'true');
                $start = $b->node('start', 260, 420, 'Restart it', 'Out of hours, try the obvious fix first.', ['machine_id' => $trigger, 'service' => $trigger], $if, 'false');
                $b->node('alert', 500, 640, 'Escalate', 'The restart didn’t work.', ['subject' => '{{service}} is down on {{machine}} and won’t start', 'message' => 'An automatic restart failed out of hours. {{run_url}}', 'tone' => 'bad'], $start, 'failed');
                $name = 'Fix it at night, call people by day';
                break;

            case 'crash-loop':
                $t = $b->node('trigger', 0, 0, 'When a service keeps restarting', 'Several restarts in a few minutes.', ['event' => 'service.crash_loop']);
                $stop = $b->node('stop', 0, 200, 'Stop it', 'So it can’t keep hammering what it depends on.', ['machine_id' => $trigger, 'service' => $trigger], $t);
                $b->node('alert', 0, 400, 'Tell the team', 'Someone needs to fix it and start it.', ['subject' => 'Stopped {{service}} on {{machine}}: it kept crashing', 'message' => 'It was restarting over and over, so ServerOS stopped it. Fix the cause, then start it from its page.', 'tone' => 'bad'], $stop);
                $b->node('alert', 260, 400, 'Couldn’t stop it', 'Needs a person now.', ['subject' => '{{service}} is crash-looping on {{machine}}', 'message' => 'ServerOS couldn’t stop it. {{run_url}}', 'tone' => 'bad'], $stop, 'failed');
                $name = 'Stop a crash loop';
                break;

            default:
                $b->node('trigger', 0, 0, 'Run it yourself', 'Starts when you press Run.', ['event' => 'manual']);
                $name = 'Untitled workflow';
        }

        return ['name' => $name, 'graph' => $b->graph()];
    }
}
