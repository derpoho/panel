<?php

namespace App\Mcp\Tools\Machine;

use App\Http\Controllers\MachineUpdatesController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;

/**
 * Install operating system updates on a machine, as the updates dialog
 * does: everything, security updates only, or chosen packages.
 */
final class InstallUpdates extends Tool
{
    /** Installing can take a while on a server that's far behind. */
    private const TIMEOUT = 1800;

    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'install_updates';
    }

    public function title(): string
    {
        return 'Install operating system updates';
    }

    public function description(): string
    {
        return 'Installs operating system updates on a machine with its package manager: all of them, only security updates (security_only), or just the packages named (names as get_updates_result lists them; needs a daemon that can, see canPick). Package upgrades can restart the services they belong to, so ask the person first and pass confirm set to the machine’s name exactly. One install at a time per machine. '
            .'It runs as a job that can take many minutes: pass wait (up to 25 seconds) or follow it with get_updates_result (or get_job). If the result says a reboot is needed, reboot_machine does that, once the person agrees.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'confirm' => Schema::confirm('the machine’s name'),
            'security_only' => Schema::boolean('Only security updates (default false: everything waiting). Ignored when packages are named.'),
            'packages' => Schema::array(Schema::string('A package name, like openssl or libc6:amd64.', maxLength: 200), 'Just these packages, by name.', 500),
            'wait' => ServerWork::waitSchema(),
        ], ['machine', 'confirm']);
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
            'confirm' => ['nullable', 'string', 'max:200'],
            'security_only' => ['sometimes', 'boolean'],
            // Names as the package manager lists them, never starting with
            // a dash so they can't pass for options.
            'packages' => ['sometimes', 'array', 'list', 'min:1', 'max:500'],
            'packages.*' => ['string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9+._:~-]*$/'],
            'wait' => ServerWork::waitRules(),
        ], ['packages.*.regex' => 'Package names are letters, digits and + . _ : ~ -, and can’t start with a dash.']);
        $machine = $context->machine($data['machine']);
        ServerWork::confirm($data, $machine->name, 'machine', 'Package upgrades can restart the services they belong to.');
        ServerWork::throttle($context, 'install-updates', 5);
        // Named packages are installed as named; security_only doesn't apply.
        $securityOnly = ! isset($data['packages']) && (bool) ($data['security_only'] ?? false);

        if (($running = ServerWork::installing($machine)) !== null) {
            throw new ToolError("Updates are already being installed on this machine (job {$running->id}). get_updates_result follows it.");
        }
        if (isset($data['packages']) && ! MachineUpdatesController::canPick($machine)) {
            throw new ToolError('This machine’s daemon can only install every update. Update it with “sudo serverosd update” to pick which ones.');
        }

        // A daemon that doesn't know package_install refuses it, rather
        // than upgrading everything.
        $packages = isset($data['packages']) ? array_values(array_unique($data['packages'])) : null;
        [$kind, $job] = $packages !== null
            ? ['package_install', ['type' => 'package_install', 'packages' => $packages]]
            : ['package_updates', ['type' => 'package_updates', 'apply' => true, 'security_only' => $securityOnly]];

        $record = $this->work->queue($context, $machine, $kind, $job, self::TIMEOUT);
        $context->record('machine.update', $machine->name, $machine, [
            'security_only' => $securityOnly,
            'packages' => $packages !== null ? count($packages) : null,
        ]);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...MachineView::updatesJob($machine, $context->waitForJob($record, (int) ($data['wait'] ?? 0))),
        ];
    }
}
