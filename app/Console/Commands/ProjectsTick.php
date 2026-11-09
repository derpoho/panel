<?php

namespace App\Console\Commands;

use App\Models\ProjectEnvironment;
use App\Support\Projects\Environments;
use App\Support\Projects\ProjectData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: starts copies of a database's data that were waiting (for
 * a deploy, the private network or the machine), settles copies that never
 * reported back, copies the data again into environments set to refresh
 * daily or weekly, and carries on removing environments being deleted.
 */
#[Signature('projects:tick')]
#[Description('Start waiting data copies and refresh environments on their schedule')]
class ProjectsTick extends Command
{
    public function handle(ProjectData $data, Environments $environments): int
    {
        $data->tick(CarbonImmutable::now());

        // Environments being removed: take what's left off their machines.
        foreach (ProjectEnvironment::query()->whereNotNull('removing_at')->with('project.workspace.owner')->get() as $environment) {
            $owner = $environment->project->workspace->owner;
            if ($owner !== null) {
                $environments->continueRemoving($environment, $owner);
            }
        }

        return self::SUCCESS;
    }
}
