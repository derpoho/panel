<?php

use App\Mcp\Tools;

return [
    /*
     * The tool groups the MCP server offers, in the order tools/list
     * shows them.
     */
    'groups' => [
        Tools\WorkspaceTools::class,
        Tools\MachineTools::class,
        Tools\ServiceTools::class,
        Tools\DeployTools::class,
        Tools\ProjectTools::class,
        Tools\MonitorTools::class,
        Tools\StatusPageTools::class,
        Tools\WorkflowTools::class,
        Tools\BackupTools::class,
        Tools\LogTools::class,
        Tools\TeamTools::class,
    ],

    // Requests one connection may make a minute.
    'rate_limit' => (int) env('MCP_RATE_LIMIT', 120),
];
