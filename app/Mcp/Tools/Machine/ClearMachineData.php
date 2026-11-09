<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Machines\MachineDataEraser;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Clear what a machine has sent ServerOS while it stays connected, as its
 * settings page does: logs, usage history, discovered services.
 */
final class ClearMachineData extends Tool
{
    public function __construct(private readonly MachineDataEraser $eraser) {}

    public function name(): string
    {
        return 'clear_machine_data';
    }

    public function title(): string
    {
        return 'Clear a machine’s collected data';
    }

    public function description(): string
    {
        return 'Deletes what a machine has sent ServerOS, while it stays connected: logs (its stored log lines and their counts), metrics (its CPU, memory, disk and per-service usage history) and services (services discovery found that nobody adopted or deployed, with its ports, scheduled tasks and firewall rules; ServerOS finds what’s running again on the next scan, which is asked for straight away). '
            .'Kept: the connection, settings, adopted and deployed services, deploy history, backups, monitors, status pages, service groups and the names and icons given to services. Big clears run in the background. '
            .'Can’t be undone: ask the person first, then pass confirm set to the machine’s name exactly. With confirm empty ("") it changes nothing and says how much of each kind there is, to tell the person.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'kinds' => Schema::array(Schema::string('A kind of collected data.', MachineDataEraser::KINDS), 'What to clear: logs, metrics, services, or any mix.', count(MachineDataEraser::KINDS)),
            'confirm' => Schema::confirm('the machine’s name'),
        ], ['machine', 'kinds', 'confirm']);
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
            'machine' => ['required', ServerWork::reference()],
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', 'in:'.implode(',', MachineDataEraser::KINDS)],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $machine = $context->machine($data['machine']);
        $kinds = MachineDataEraser::only($data['kinds']);

        $summary = $this->eraser->summary($machine);
        $sizes = array_filter([
            in_array(MachineDataEraser::LOGS, $kinds, true) ? ($summary['logs']['unavailable'] ? 'logs (the workspace’s own log store isn’t answering right now)' : number_format((int) $summary['logs']['lines']).' log lines') : null,
            in_array(MachineDataEraser::METRICS, $kinds, true) ? number_format($summary['metrics']['readings']).' usage readings' : null,
            in_array(MachineDataEraser::SERVICES, $kinds, true) ? $summary['services']['discovered'].' discovered services' : null,
        ]);
        ServerWork::confirm($data, $machine->name, 'machine', 'This deletes '.implode(', ', $sizes).' from '.$machine->name.' and can’t be undone. The machine stays connected.');

        try {
            $result = $this->eraser->start($machine, $kinds, $context->user);
        } catch (QueryException|PDOException) {
            throw new ToolError("Couldn’t reach where {$machine->name}’s logs are kept. Nothing was cleared from there; try again once it’s back.");
        }
        $context->record('machine.data_clear', $machine->name, $machine, MachineDataEraser::activityMeta($result['counts'], $result['queued']));

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'queued' => $result['queued'],
            ($result['queued'] ? 'clearing' : 'cleared') => $result['counts'],
            'note' => ($result['queued'] ? 'Clearing in the background; it takes a few minutes for millions of lines.' : 'Cleared.')
                .' The machine stays connected.'
                .(in_array(MachineDataEraser::SERVICES, $kinds, true) ? ' ServerOS will find what’s running again on the next scan.' : ''),
        ];
    }
}
