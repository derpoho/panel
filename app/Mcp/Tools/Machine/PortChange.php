<?php

namespace App\Mcp\Tools\Machine;

use App\Http\Controllers\MachineFirewallController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Support\Firewall;

/**
 * What open_port and close_port share: the arguments, the checks the
 * Ports tab makes, and the job.
 */
final class PortChange
{
    /** @return array<string, array<string, mixed>> */
    public static function schema(): array
    {
        return [
            'machine' => Schema::machine(),
            'port' => Schema::integer('The port, 1–65535.', 1, 65535),
            'proto' => Schema::string('tcp (the default) or udp.', ['tcp', 'udp']),
            'wait' => ServerWork::waitSchema(),
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'machine' => ['required', ServerWork::reference()],
            'port' => ['required', 'integer', 'between:1,65535'],
            'proto' => ['nullable', 'in:tcp,udp'],
            'confirm' => ['nullable', 'string', 'max:200'],
            'wait' => ServerWork::waitRules(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function run(McpContext $context, ServerWork $work, Machine $machine, string $action, array $data): array
    {
        $port = (int) $data['port'];
        $proto = $data['proto'] ?? 'tcp';

        $refused = $action === 'close'
            ? Firewall::refuseClose($machine, $port, $proto)
            : (Firewall::report($machine) === null ? Firewall::unavailable(null) : null);
        if ($refused !== null) {
            throw new ToolError($refused.' Nothing was changed.');
        }
        if (MachineFirewallController::changing($machine)) {
            throw new ToolError("The firewall is already being changed on {$machine->name}. Try again in a moment. Nothing was changed.");
        }
        ServerWork::throttle($context, 'firewall', 20);

        $job = $work->queue($context, $machine, 'firewall_rule', MachineFirewallController::job($action, $port, $proto), 120);
        $context->record('machine.firewall', $machine->name, $machine, ['action' => $action, 'port' => "{$port}/{$proto}"]);
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'port' => "{$port}/{$proto}",
            'job' => Present::job($job),
            'note' => match ($job->state) {
                'succeeded' => "{$port}/{$proto} is ".match ($job->result['exposure'] ?? null) {
                    'open' => 'now open to the internet',
                    'restricted' => 'now reachable only from the addresses its rules allow',
                    'blocked' => 'now blocked',
                    'docker' => 'already reachable',
                    default => 'changed',
                }.' ('.($job->result['because'] ?? 'checked after the change').').',
                'failed', 'refused', 'timed_out', 'cancelled' => 'It isn’t done: job.error says why, and get_firewall shows where it stands now.',
                default => 'The machine is changing its firewall. get_firewall shows the result once the job has finished.',
            },
        ];
    }
}
