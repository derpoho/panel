<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Models\User;
use App\Support\Machines\MachineDataEraser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * A big clear of a machine's collected data, in the background. Safe to
 * run again: it deletes whatever is still there. It carries ids, not a
 * connection: a workspace's own log store is registered as the job runs.
 */
class ClearMachineData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /**
     * @param  list<string>  $kinds
     * @param  list<string>  $services  Only these services' logs (skipped ones), instead of the kinds.
     */
    public function __construct(
        public int $machineId,
        public array $kinds,
        public ?int $userId = null,
        public bool $rescan = true,
        public array $services = [],
    ) {}

    public function handle(MachineDataEraser $eraser): void
    {
        $machine = Machine::query()->find($this->machineId);
        if ($machine === null) {
            return;
        }

        $only = $this->services === [] ? [] : ['services' => $this->services];
        MachineDataEraser::setStatus($machine, ['state' => 'running', 'kinds' => $this->kinds, 'counts' => MachineDataEraser::status($machine)['counts'] ?? [], ...$only]);
        if ($this->services !== []) {
            $counts = [MachineDataEraser::LOGS => $eraser->clearServiceLogs($machine, $this->services)];
        } else {
            $user = $this->userId === null ? null : User::query()->find($this->userId);
            $counts = $eraser->clear($machine, $this->kinds, $user, $this->rescan);
        }
        MachineDataEraser::setStatus($machine, ['state' => 'done', 'kinds' => $this->kinds, 'counts' => $counts, ...$only]);
    }

    public function failed(?Throwable $e): void
    {
        MachineDataEraser::setStatus($this->machineId, ['state' => 'failed', 'kinds' => $this->kinds, 'counts' => []]);
    }
}
