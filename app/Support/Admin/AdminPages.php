<?php

namespace App\Support\Admin;

use App\Support\Extensions\AdminNavItem;
use App\Support\Extensions\Extensions;

/**
 * The admin pages the panel serves itself, in the staff admin's sidebar
 * (App\Support\Extensions\AdminNavItem): Laravel Pulse. Everything else
 * under /admin is a package's, which adds its own; each says where it goes
 * among them, so the order is the same whichever adds first.
 */
final class AdminPages
{
    public static function register(Extensions $extensions): void
    {
        // Laravel Pulse: its own page, not an Inertia one.
        $extensions->addAdminNavItem(new AdminNavItem(
            key: 'pulse', label: 'Pulse', icon: 'heart-pulse', path: '/admin/pulse',
            group: 'platform', after: 'system', external: true,
        ));
    }
}
