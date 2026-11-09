<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Support\Machines\PackageUpdates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A fresh check of a machine's OS updates, a moment after an install, so
 * what the panel shows as waiting is what's really left.
 */
class CheckMachineUpdates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $machineId) {}

    public function handle(): void
    {
        $machine = Machine::query()->find($this->machineId);
        if ($machine !== null) {
            PackageUpdates::check($machine);
        }
    }
}
