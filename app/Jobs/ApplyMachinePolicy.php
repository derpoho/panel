<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Support\Machines\MachinePolicies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a machine its policy (MachinePolicies::sync), in the background so
 * saving a policy that 100 machines follow doesn't wait on each of them.
 * After a hello it carries what the daemon reported, so only what differs
 * is sent.
 */
class ApplyMachinePolicy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param  array<string, mixed>|null  $reported */
    public function __construct(public int $machineId, public ?array $reported = null) {}

    public function handle(): void
    {
        $machine = Machine::query()->find($this->machineId);
        if ($machine !== null) {
            MachinePolicies::sync($machine, $this->reported);
        }
    }
}
