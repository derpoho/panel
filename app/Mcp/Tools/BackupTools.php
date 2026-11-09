<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Backup\ListBackupDestinations;
use App\Mcp\Tools\Backup\ListBackups;
use App\Mcp\Tools\Backup\RestoreBackup;
use App\Mcp\Tools\Backup\RunBackup;
use App\Mcp\Tools\Backup\UpdateBackupSettings;

/**
 * Backups: what has run and how it went, where they go, backing up now,
 * restoring one, and each machine's schedule.
 */
final class BackupTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListBackups::class,
            ListBackupDestinations::class,
            RunBackup::class,
            RestoreBackup::class,
            UpdateBackupSettings::class,
        ];
    }
}
