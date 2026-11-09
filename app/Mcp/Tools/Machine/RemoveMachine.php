<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Models\BackupDestination;
use App\Models\Machine;

/**
 * Remove a machine from the workspace, as its settings page does. Its
 * jobs go with it; nothing on the server itself is touched.
 */
final class RemoveMachine extends Tool
{
    public function name(): string
    {
        return 'remove_machine';
    }

    public function title(): string
    {
        return 'Remove a machine';
    }

    public function description(): string
    {
        return 'Removes a machine from ServerOS, with its deploy and job history, service groups, status page, backup records and logs. If it receives other machines’ backups (an SFTP backup destination), that destination goes too and those machines stop backing up until given another. Its monitors stay, no longer tied to it. '
            .'Nothing on the machine is touched: its services keep running, but ServerOS stops watching, alerting on and backing it up, and the daemon there is no longer accepted (to bring the machine back, connect it again with add_machine; to clean up the machine, run “serverosd disconnect” on it). Can’t be undone: ask the person first, then pass confirm set to the machine’s name exactly. With confirm empty ("") it changes nothing and says what would go with it, to tell the person.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'confirm' => Schema::confirm('the machine’s name'),
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
        ]);
        $machine = $context->machine($data['machine']);

        // What the database takes with it, to say before and after.
        $destinations = BackupDestination::query()->where('workspace_id', $context->workspace->id)->where('machine_id', $machine->id)->get();
        $senders = $destinations->isEmpty() ? collect() : $context->workspace->machines()
            ->whereIn('backup_destination_id', $destinations->modelKeys())
            ->whereKeyNot($machine->id)
            ->orderBy('name')
            ->get();
        $warning = 'This can’t be undone.'.($destinations->isEmpty() ? '' : ' It also removes the backup destination '
            .$destinations->map(fn (BackupDestination $d) => "“{$d->name}”")->implode(', ').' it receives backups for'
            .($senders->isEmpty() ? '.' : ', so '.$senders->pluck('name')->implode(', ').' will stop backing up until given another.'));
        ServerWork::confirm($data, $machine->name, 'machine', $warning);

        $name = $machine->name;
        $id = $machine->id;
        $context->record('machine.remove', $name);
        $machine->delete();

        return [
            'removed' => ['id' => $id, 'name' => $name],
            'backupDestinationsRemoved' => $destinations->map(fn (BackupDestination $d) => ['id' => $d->id, 'name' => $d->name])->values()->all(),
            'machinesWithoutBackupDestination' => $senders->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name])->values()->all(),
            'note' => "Removed {$name}. Nothing on the machine was changed.",
        ];
    }
}
