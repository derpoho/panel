<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\DaemonJobQueue;

/**
 * Remove a service from its machine, as "Remove service" on its page. A
 * container stops and is deleted, and with delete_data its volumes too (an
 * adopted one's take delete_adopted_volumes as well). A systemd service
 * stops, is turned off at boot, and loses its unit file if it was made on
 * the machine. One ServerOS only found is adopted first, then removed.
 */
final class RemoveService extends Tool
{
    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'remove_service';
    }

    public function title(): string
    {
        return 'Remove a service';
    }

    public function description(): string
    {
        return 'Removes a service from its machine: a container, or a systemd service (on a daemon new enough). This can’t be undone: tell the person exactly what will go (and whether data will), get their go-ahead, then pass confirm set to the service’s name on the machine exactly (foundAs in list_services, which the dashboard asks to type too). '
            .'Created by ServerOS (managedBy in get_service): its containers stop and are deleted, and if it belongs to a Compose project (details.composeProject), everything in that project goes with it; delete_data also deletes its volumes for good. '
            .'Adopted: only that one container goes, nothing else in its Compose project. Its Docker volumes are kept unless delete_data and delete_adopted_volumes are both true; folders on the machine it uses (bind mounts) are never touched. '
            .'A systemd service stops, won’t start at boot, and its unit file is deleted if it was made on the machine (a package’s stays; uninstall the package to remove it completely); its files and data are never touched, so delete_data doesn’t apply. Units the machine needs (SSH, Docker, systemd’s own) are refused. '
            .'Discovered only (not adopted): it is adopted first, and the removal follows once that succeeds; the job returned is the adopt. Parts of ServerOS itself are refused. Needs an online machine. Returns the job; pass wait (up to 25 seconds) or follow it with get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'confirm' => Schema::confirm('the service’s name on the machine (foundAs)'),
            'delete_data' => Schema::boolean('Also delete its data (volumes). Default false. This can’t be undone.'),
            'delete_adopted_volumes' => Schema::boolean('For an adopted service, a second yes to delete_data: deletes its Docker volumes. Default false. Folders on the machine are never touched.'),
            'wait' => ServiceWork::waitSchema(),
        ], ['machine', 'service', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...ServiceWork::serviceArguments(),
            'confirm' => ['nullable', 'string', 'max:200'],
            'delete_data' => ['sometimes', 'boolean'],
            'delete_adopted_volumes' => ['sometimes', 'boolean'],
            'wait' => ServiceWork::waitRules(),
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);
        // The same order as the service page: the name, then what it is.
        ServiceWork::confirm($data, [(string) ($service['name'] ?? $service['key'])], 'service');
        $key = (string) $service['key'];
        $container = str_starts_with($key, 'docker:');
        if (! $container && ! (str_starts_with($key, 'systemd:') && DaemonJobQueue::canRemoveSystemd($machine))) {
            throw new ToolError(str_starts_with($key, 'systemd:')
                ? "{$machine->name}’s daemon is too old to remove a systemd service. It updates itself, or run “sudo serverosd update” on the machine."
                : 'ServerOS can only remove containers and systemd services.');
        }
        if (! $container && ($data['delete_data'] ?? false)) {
            throw new ToolError('A systemd service has no volumes: removing it never touches its files or data. Leave delete_data out.');
        }

        $adopted = ($service['origin'] ?? null) !== 'created';
        $deleteData = (bool) ($data['delete_data'] ?? false);
        $deleteAdoptedVolumes = (bool) ($data['delete_adopted_volumes'] ?? false);
        if ($adopted && $deleteData && ! $deleteAdoptedVolumes) {
            throw new ToolError("{$service['display']} was adopted, so deleting its data needs delete_adopted_volumes set to true as well. Check with the person first; folders on the machine are never touched either way.");
        }
        $remove = ['type' => 'service_remove', 'service' => $key, 'delete_data' => $deleteData, 'delete_adopted_volumes' => $adopted && $deleteData];
        // Found, not adopted: adopt it, and the removal follows once that's done.
        $job = ($service['managed'] ?? false) === true
            ? $this->work->queue($context, $machine, 'service_remove', $remove, $key)
            : $this->work->queue($context, $machine, 'adopt', ['type' => 'adopt', 'service' => $key, 'dry_run' => false], $key,
                then: ['kind' => 'service_remove', 'job' => $remove, 'service' => $key]);

        return [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'adopted' => $adopted,
            'deleteData' => $deleteData,
            'composeProject' => $adopted ? null : ($service['details']['compose_project'] ?? null),
            'job' => ServiceView::job($context, $context->waitForJob($job, (int) ($data['wait'] ?? 0)), 20),
            'note' => "Removing {$service['display']} from {$machine->name}".($deleteData ? ', and deleting its '.($adopted ? 'Docker volumes' : 'data') : ', keeping its data').'. Once the job succeeds it is gone from list_services.',
        ];
    }
}
