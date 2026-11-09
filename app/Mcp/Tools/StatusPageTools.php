<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\StatusPage\ArrangeStatusPage;
use App\Mcp\Tools\StatusPage\GetFleetStatusPage;
use App\Mcp\Tools\StatusPage\GetStatusPage;
use App\Mcp\Tools\StatusPage\ListStatusIncidents;
use App\Mcp\Tools\StatusPage\ListStatusPages;
use App\Mcp\Tools\StatusPage\PostStatusIncident;
use App\Mcp\Tools\StatusPage\PreviewStatusPage;
use App\Mcp\Tools\StatusPage\UpdateFleetStatusPage;
use App\Mcp\Tools\StatusPage\UpdateStatusIncident;
use App\Mcp\Tools\StatusPage\UpdateStatusPage;

/**
 * Status pages: each machine's page and the workspace's fleet page, their
 * settings, organising a page into sections, previewing what visitors see,
 * and incidents written for them.
 */
final class StatusPageTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListStatusPages::class,
            GetStatusPage::class,
            UpdateStatusPage::class,
            ArrangeStatusPage::class,
            PreviewStatusPage::class,
            GetFleetStatusPage::class,
            UpdateFleetStatusPage::class,
            ListStatusIncidents::class,
            PostStatusIncident::class,
            UpdateStatusIncident::class,
        ];
    }
}
