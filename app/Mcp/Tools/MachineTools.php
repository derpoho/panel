<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Machine\AddMachine;
use App\Mcp\Tools\Machine\CheckUpdates;
use App\Mcp\Tools\Machine\ClearMachineData;
use App\Mcp\Tools\Machine\ClosePort;
use App\Mcp\Tools\Machine\CreateMachineGroup;
use App\Mcp\Tools\Machine\CreateMachinePolicy;
use App\Mcp\Tools\Machine\CreatePackageRollout;
use App\Mcp\Tools\Machine\DeleteMachineGroup;
use App\Mcp\Tools\Machine\EndMaintenance;
use App\Mcp\Tools\Machine\GetDiskUsage;
use App\Mcp\Tools\Machine\GetFirewall;
use App\Mcp\Tools\Machine\GetMachine;
use App\Mcp\Tools\Machine\GetMachineConnectionStatus;
use App\Mcp\Tools\Machine\GetPackageRollout;
use App\Mcp\Tools\Machine\GetUpdatesResult;
use App\Mcp\Tools\Machine\InstallPackages;
use App\Mcp\Tools\Machine\InstallUpdates;
use App\Mcp\Tools\Machine\ListMachineGroups;
use App\Mcp\Tools\Machine\ListMachinePolicies;
use App\Mcp\Tools\Machine\ListMachines;
use App\Mcp\Tools\Machine\ListPackageUpdates;
use App\Mcp\Tools\Machine\OpenPort;
use App\Mcp\Tools\Machine\RebootMachine;
use App\Mcp\Tools\Machine\RemoveMachine;
use App\Mcp\Tools\Machine\RemoveMachineIcon;
use App\Mcp\Tools\Machine\RescanMachine;
use App\Mcp\Tools\Machine\SetMachineIcon;
use App\Mcp\Tools\Machine\StartMaintenance;
use App\Mcp\Tools\Machine\UpdateMachine;
use App\Mcp\Tools\Machine\UpdateMachineGroup;
use App\Mcp\Tools\Machine\UpdateMachinePolicy;

/**
 * Machines: seeing them, organising them into groups, maintenance
 * windows, operating system updates and reboots, the firewall, rescans,
 * and connecting and removing servers.
 */
final class MachineTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListMachines::class,
            GetMachine::class,
            UpdateMachine::class,
            SetMachineIcon::class,
            RemoveMachineIcon::class,
            ListMachineGroups::class,
            CreateMachineGroup::class,
            UpdateMachineGroup::class,
            DeleteMachineGroup::class,
            ListMachinePolicies::class,
            CreateMachinePolicy::class,
            UpdateMachinePolicy::class,
            StartMaintenance::class,
            EndMaintenance::class,
            RescanMachine::class,
            CheckUpdates::class,
            GetUpdatesResult::class,
            InstallUpdates::class,
            InstallPackages::class,
            ListPackageUpdates::class,
            CreatePackageRollout::class,
            GetPackageRollout::class,
            RebootMachine::class,
            GetDiskUsage::class,
            GetFirewall::class,
            OpenPort::class,
            ClosePort::class,
            AddMachine::class,
            GetMachineConnectionStatus::class,
            ClearMachineData::class,
            RemoveMachine::class,
        ];
    }
}
