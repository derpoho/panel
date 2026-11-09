<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Workflow\CancelWorkflowRun;
use App\Mcp\Tools\Workflow\CreateWorkflow;
use App\Mcp\Tools\Workflow\DeleteWorkflow;
use App\Mcp\Tools\Workflow\GetWorkflow;
use App\Mcp\Tools\Workflow\GetWorkflowReference;
use App\Mcp\Tools\Workflow\GetWorkflowRun;
use App\Mcp\Tools\Workflow\ListWorkflowRuns;
use App\Mcp\Tools\Workflow\ListWorkflows;
use App\Mcp\Tools\Workflow\ListWorkflowTemplates;
use App\Mcp\Tools\Workflow\RunWorkflow;
use App\Mcp\Tools\Workflow\UpdateWorkflow;

/**
 * Workflows: seeing them and their runs, building them from templates or
 * as a graph of steps, turning them on and off, running them by hand,
 * cancelling runs and deleting them.
 */
final class WorkflowTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListWorkflows::class,
            GetWorkflow::class,
            GetWorkflowReference::class,
            ListWorkflowTemplates::class,
            CreateWorkflow::class,
            UpdateWorkflow::class,
            RunWorkflow::class,
            ListWorkflowRuns::class,
            GetWorkflowRun::class,
            CancelWorkflowRun::class,
            DeleteWorkflow::class,
        ];
    }
}
