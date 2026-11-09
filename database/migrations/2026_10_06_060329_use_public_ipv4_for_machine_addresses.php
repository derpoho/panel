<?php

use App\Models\Machine;
use App\Support\DaemonFacts;
use Illuminate\Database\Migrations\Migration;

/**
 * Machines enrolled over IPv6 showed that address. Re-read their saved
 * facts so they show their public IPv4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Machine::query()->whereNotNull('facts')->each(function (Machine $machine) {
            $address = DaemonFacts::address($machine->facts ?? [], $machine->ip_address);
            if ($address !== null && $address !== $machine->ip_address) {
                $machine->forceFill(['ip_address' => $address])->saveQuietly();
            }
        });
    }

    public function down(): void {}
};
