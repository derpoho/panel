<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\MaintenanceWindow;
use App\Support\Audit\AuditContext;
use App\Support\Maintenance\ScheduledMaintenance;

/**
 * End a machine's maintenance early: alerts and workflows about it are
 * back on.
 */
final class EndMaintenance extends Tool
{
    public function name(): string
    {
        return 'end_maintenance';
    }

    public function title(): string
    {
        return 'End maintenance on a machine';
    }

    public function description(): string
    {
        return 'Ends a machine’s maintenance window now, so alerts and event workflows about it are back on straight away. Does nothing if it isn’t in maintenance (the answer says so). With planned (an id from get_machine’s plannedMaintenance), calls off that planned window instead, or ends it if it’s under way.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'planned' => Schema::integer('Call off this planned window (its id from get_machine’s plannedMaintenance).'),
        ], ['machine']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', ServerWork::reference()], 'planned' => ['nullable', 'integer']]);
        $machine = $context->machine($data['machine']);

        if (isset($data['planned'])) {
            $window = MaintenanceWindow::query()->where('machine_id', $machine->id)->whereKey((int) $data['planned'])->first()
                ?? throw new ToolError("There’s no planned maintenance {$data['planned']} on {$machine->name}. get_machine lists them under plannedMaintenance.");
            $was = in_array($window->state, ['scheduled', 'active'], true);
            $context->within(fn () => app(ScheduledMaintenance::class)->cancel($window, $context->user));

            return [
                'machine' => ['id' => $machine->id, 'name' => $machine->name],
                'calledOff' => $was,
                'note' => $was ? null : 'That one had already '.($window->state === 'cancelled' ? 'been called off.' : 'finished.'),
            ];
        }

        $was = $machine->inMaintenance();
        if ($was) {
            $machine->forceFill(['maintenance_until' => null, 'maintenance_reason' => null])->save();
            $context->record('machine.maintenance_end', $machine->name, $machine);
        } else {
            AuditContext::nothingChanged();
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'ended' => $was,
            'note' => $was ? null : "{$machine->name} wasn’t in maintenance.",
        ];
    }
}
