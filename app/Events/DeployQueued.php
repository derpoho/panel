<?php

namespace App\Events;

use App\Models\DaemonJob;
use App\Models\Machine;

/**
 * A deploy was handed to a machine's daemon (DaemonJobQueue::deploy). It's
 * dispatched once the gateway has taken it, and listeners run before
 * deploy() returns.
 */
final readonly class DeployQueued
{
    /**
     * @param  array<mixed>  $domains  the deploy's domains, as it sent them
     */
    public function __construct(
        public Machine $machine,
        public DaemonJob $job,
        public array $domains,
    ) {}
}
