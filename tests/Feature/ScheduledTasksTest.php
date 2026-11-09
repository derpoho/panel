<?php

use App\Enums\Plan;
use App\Mcp\Tools\MachineTools;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A machine whose scan found a crontab line for a container, a script in
 * cron.daily, a timer with its schedule and runs (a newer daemon), and a
 * timer as older daemons send it.
 *
 * @return array{0: Workspace, 1: Machine}
 */
function scheduledMachine(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [],
            'services' => [
                ['key' => 'docker:abc123abc123', 'name' => 'shop-db', 'kind' => 'database', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90, 'details' => []],
                ['key' => 'systemd:certbot.service', 'name' => 'certbot', 'kind' => 'other', 'manager' => 'systemd', 'status' => 'stopped', 'ports' => [], 'confidence' => 90, 'details' => []],
            ],
            'scheduled' => [
                ['source' => 'crontab:root', 'schedule' => '0 3 * * *', 'command' => 'docker exec shop-db pg_dumpall > /root/dump.sql', 'user' => 'root'],
                ['source' => '/etc/cron.daily', 'schedule' => '@daily', 'command' => '/etc/cron.daily/backup-2021', 'user' => 'root'],
                ['source' => 'systemd-timer', 'schedule' => '*-*-* 00,12:00:00', 'command' => 'certbot.service', 'unit' => 'certbot.timer',
                    'description' => 'Run certbot twice daily', 'next_run' => 'Thu 2026-10-08 00:00:00 UTC', 'last_run' => 'Wed 2026-10-07 12:00:03 UTC'],
                ['source' => 'systemd-timer', 'schedule' => 'logrotate.timer', 'command' => 'logrotate.service'],
            ],
        ],
    ])->save();

    return [$workspace, $machine];
}

test('the machine page lists cron jobs and timers in plain words, linked to their services', function () {
    [$workspace, $machine] = scheduledMachine();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page
            ->has('scheduled', 4)
            ->where('scheduled.0.command', '/etc/cron.daily/backup-2021')
            ->where('scheduled.0.when', 'every day')
            ->where('scheduled.1.when', 'every day at 03:00')
            ->where('scheduled.1.user', 'root')
            ->where('scheduled.1.service', ['key' => 'docker:abc123abc123', 'name' => 'shop-db'])
            ->where('scheduled.2.when', 'every day at 00:00 and 12:00')
            ->where('scheduled.2.description', 'Run certbot twice daily')
            ->where('scheduled.2.nextRun', '2026-10-08T00:00:00+00:00')
            ->where('scheduled.2.service.key', 'systemd:certbot.service')
            // An older daemon only said which timer; there's no schedule to put into words.
            ->where('scheduled.3.when', null)
            ->where('scheduled.3.source', 'logrotate.timer')
            ->etc());
});

test('get_machine includes the scheduled tasks', function () {
    config()->set('mcp.groups', [MachineTools::class]);
    [$workspace, $machine] = scheduledMachine();

    $tasks = mcpCall($workspace, mcpKey($workspace), 'get_machine', ['machine' => 'web-1'])['data']['scheduledTasks'];

    expect($tasks)->toHaveCount(4)
        ->and($tasks[1])->toMatchArray(['when' => 'every day at 03:00', 'service' => 'docker:abc123abc123', 'kind' => 'cron'])
        ->and($tasks[3])->not->toHaveKey('when');
});
