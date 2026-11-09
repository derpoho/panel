<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Service\AdoptService;
use App\Mcp\Tools\Service\CancelJob;
use App\Mcp\Tools\Service\CloneService;
use App\Mcp\Tools\Service\CreateServiceGroup;
use App\Mcp\Tools\Service\DeleteServiceGroup;
use App\Mcp\Tools\Service\DetectServices;
use App\Mcp\Tools\Service\GetJob;
use App\Mcp\Tools\Service\GetService;
use App\Mcp\Tools\Service\GetServiceLogs;
use App\Mcp\Tools\Service\HideService;
use App\Mcp\Tools\Service\ListJobs;
use App\Mcp\Tools\Service\ListServiceGroups;
use App\Mcp\Tools\Service\ListServices;
use App\Mcp\Tools\Service\ReloadService;
use App\Mcp\Tools\Service\RemoveService;
use App\Mcp\Tools\Service\RemoveServiceGroupIcon;
use App\Mcp\Tools\Service\RemoveServiceIcon;
use App\Mcp\Tools\Service\RenameService;
use App\Mcp\Tools\Service\RepairService;
use App\Mcp\Tools\Service\RestartService;
use App\Mcp\Tools\Service\RetryJob;
use App\Mcp\Tools\Service\RunCommand;
use App\Mcp\Tools\Service\SetServiceGroupIcon;
use App\Mcp\Tools\Service\SetServiceIcon;
use App\Mcp\Tools\Service\StartService;
use App\Mcp\Tools\Service\StopService;
use App\Mcp\Tools\Service\UnhideService;
use App\Mcp\Tools\Service\UpdateServiceGroup;

/**
 * Services: seeing what runs on the machines, starting, stopping and
 * restarting it, its logs and a console, adopting and removing it, names,
 * icons and groups, and following the server jobs all of this (and the
 * other groups' work) runs as.
 */
final class ServiceTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListServices::class,
            GetService::class,
            StartService::class,
            RestartService::class,
            ReloadService::class,
            StopService::class,
            AdoptService::class,
            RemoveService::class,
            RepairService::class,
            CloneService::class,
            GetServiceLogs::class,
            RunCommand::class,
            RenameService::class,
            HideService::class,
            UnhideService::class,
            SetServiceIcon::class,
            RemoveServiceIcon::class,
            ListServiceGroups::class,
            CreateServiceGroup::class,
            UpdateServiceGroup::class,
            DeleteServiceGroup::class,
            SetServiceGroupIcon::class,
            RemoveServiceGroupIcon::class,
            GetJob::class,
            ListJobs::class,
            RetryJob::class,
            CancelJob::class,
            DetectServices::class,
        ];
    }
}
