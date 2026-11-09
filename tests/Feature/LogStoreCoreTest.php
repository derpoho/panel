<?php

use App\Enums\Plan;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Logs\LogQuota;
use App\Support\Logs\LogStore;

test('a workspace with its own store reads and writes there, others use ours', function () {
    $ours = Workspace::factory()->onPlan(Plan::Business)->create();
    $theirs = Workspace::factory()->onPlan(Plan::Business)->create();
    $machine = Machine::factory()->for($theirs)->create();
    ownLogStore($theirs);

    expect(LogStore::connection($ours))->toBe(LogStore::ours())
        ->and(LogStore::isExternal($theirs))->toBeTrue()
        ->and(LogStore::connectionForMachine($machine->id))->toBe('logs_ws_'.$theirs->id);

    LogStore::entries($theirs)->insert(['machine_id' => $machine->id, 'service_key' => 'docker:a', 'service' => 'a', 'logged_at' => 1, 'level' => 'info', 'stream' => 'stdout', 'message' => 'hi']);
    expect(LogStore::entries($theirs)->count())->toBe(1)
        ->and(LogStore::entries($ours)->count())->toBe(0);
});

test('a workspace off Business writes to ours again, daily limit and all, until it’s back', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    ownLogStore($workspace);

    expect(LogStore::connection($workspace))->toBe(LogStore::ours())
        ->and(LogStore::isExternal($workspace))->toBeFalse()
        ->and(LogQuota::today($workspace)['exempt'])->toBeFalse();

    $workspace->forceFill(['plan' => Plan::Business->value])->save();
    LogStore::flush();
    expect(LogStore::isExternal($workspace->fresh()))->toBeTrue();
});

test('a failed connection is explained in plain words', function (string $driver, string $expected) {
    $store = new WorkspaceLogStore(['host' => 'db.example.com', 'port' => 5432, 'database' => 'logs', 'username' => 'serveros']);

    expect(LogStore::explain(new RuntimeException($driver), $store))->toContain($expected);
})->with([
    ['SQLSTATE[08006] [7] connection to server at "db.example.com", port 5432 failed: timeout expired', 'Timed out reaching db.example.com:5432'],
    ['SQLSTATE[08006] [7] connection to server failed: Connection refused', 'refused the connection'],
    ['SQLSTATE[08006] [7] FATAL: password authentication failed for user "serveros"', 'The user or password isn’t right.'],
    ['SQLSTATE[08006] [7] FATAL: database "logs" does not exist', 'There’s no database called logs'],
    ['SQLSTATE[08006] [7] server does not support SSL, but SSL was required', 'doesn’t accept encrypted (SSL) connections'],
    ['SQLSTATE[08006] [7] FATAL: no pg_hba.conf entry for host "1.2.3.4"', 'pg_hba.conf'],
    ['something nobody planned for', 'Couldn’t connect: something nobody planned for'],
]);
