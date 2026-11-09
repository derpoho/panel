<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\DaemonJobQueue;
use App\Support\Machines\PackageUpdates;

/**
 * Install packages a machine doesn't have yet (git, unzip, build-essential)
 * from the repositories it already has, with apt or dnf. A fixed daemon
 * job (package_add), not a shell: it can't add repositories, remove
 * anything or run commands.
 */
final class InstallPackages extends Tool
{
    /** Most packages in one go: a few tools, not a system. */
    public const MAX = 20;

    /** Refreshing apt's lists and installing can take a few minutes. */
    private const TIMEOUT = 900;

    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'install_packages';
    }

    public function title(): string
    {
        return 'Install packages on a machine';
    }

    public function description(): string
    {
        return 'Installs operating system packages a machine doesn’t have yet, by name, from the repositories it already has (apt on Debian and Ubuntu, dnf or yum on RHEL-likes): for example git, which deploys from a Git repository need on the machine, or unzip. Up to '.self::MAX.' at a time. It can’t add repositories, remove packages or run commands, and packages already installed are left as they are (to upgrade those, use install_updates). '
            .'Installing changes the machine, so ask the person first and pass confirm set to the machine’s name exactly. It runs as a job: pass wait (up to 25 seconds) or follow it with get_job. Needs daemon 0.1.1-pre.35 or newer.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'packages' => Schema::array(Schema::string('A package name as the machine’s package manager knows it, like git or build-essential.', maxLength: 200), 'The packages to install.', self::MAX),
            'confirm' => Schema::confirm('the machine’s name'),
            'wait' => ServerWork::waitSchema(),
        ], ['machine', 'packages', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServerWork::reference()],
            'packages' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX],
            // Never starting with a dash (an option) or ending with one (apt reads "nginx-" as remove nginx).
            'packages.*' => ['string', 'max:200', 'regex:'.PackageUpdates::NAME_PATTERN, 'not_regex:/-$/'],
            'confirm' => ['nullable', 'string', 'max:200'],
            'wait' => ServerWork::waitRules(),
        ], [
            'packages.*.regex' => 'Package names are letters, digits and + . _ : ~ -, and can’t start with a dash.',
            'packages.*.not_regex' => 'A package name can’t end with a dash.',
        ]);
        $machine = $context->machine($data['machine']);
        ServerWork::confirm($data, $machine->name, 'machine', 'Installing packages changes the machine.');
        ServerWork::throttle($context, 'install-packages', 5);

        if (! DaemonJobQueue::canAddPackages($machine)) {
            throw new ToolError("{$machine->name}’s daemon is too old to install new packages. Update it with “sudo serverosd update” on the machine, or install them over SSH.");
        }
        if (($running = ServerWork::installing($machine)) !== null) {
            throw new ToolError("Packages are already being installed on this machine (job {$running->id}). Wait for it to finish: get_job follows it.");
        }

        $packages = array_values(array_unique($data['packages']));
        $job = $this->work->queue($context, $machine, 'package_add', ['type' => 'package_add', 'packages' => $packages], self::TIMEOUT);
        $context->record('machine.packages_add', $machine->name, $machine, ['packages' => $packages]);
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'packages' => $packages,
            'jobId' => $job->id,
            'state' => $job->state,
            'job' => Present::job($job, 20),
            'url' => $context->url("/machines/{$machine->id}"),
        ];
    }
}
