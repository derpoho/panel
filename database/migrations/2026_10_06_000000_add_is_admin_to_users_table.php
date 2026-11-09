<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ServerOS staff, who can open /admin. Set from the admin pages (or
        // `php artisan admin:grant`).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        // Whoever SERVEROS_ADMIN_EMAILS named keeps their access.
        $emails = array_filter(array_map(
            fn (string $email) => strtolower(trim($email)),
            explode(',', (string) config('serveros.legacy_admin_emails', '')),
        ));
        if ($emails !== []) {
            DB::table('users')->whereIn(DB::raw('lower(email)'), $emails)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
