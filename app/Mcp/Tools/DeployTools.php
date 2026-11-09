<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Deploy\CheckDomain;
use App\Mcp\Tools\Deploy\DeployFromGit;
use App\Mcp\Tools\Deploy\DeployNewVersion;
use App\Mcp\Tools\Deploy\DeployTemplate;
use App\Mcp\Tools\Deploy\DetectRepository;
use App\Mcp\Tools\Deploy\GetApp;
use App\Mcp\Tools\Deploy\GetConnection;
use App\Mcp\Tools\Deploy\ListDeploys;
use App\Mcp\Tools\Deploy\ListTemplates;
use App\Mcp\Tools\Deploy\RedeployApp;
use App\Mcp\Tools\Deploy\RollbackApp;
use App\Mcp\Tools\Deploy\SetAppDomain;
use App\Mcp\Tools\Deploy\UpdateAppSettings;

/**
 * Deploys: the template library, working out what a repository is,
 * deploying templates and Git repositories, new versions, redeploys and
 * rollbacks, domains and their DNS, and the deploys and apps on record.
 */
final class DeployTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListTemplates::class,
            DetectRepository::class,
            DeployTemplate::class,
            DeployFromGit::class,
            ListDeploys::class,
            GetApp::class,
            GetConnection::class,
            DeployNewVersion::class,
            RedeployApp::class,
            RollbackApp::class,
            SetAppDomain::class,
            UpdateAppSettings::class,
            CheckDomain::class,
        ];
    }
}
