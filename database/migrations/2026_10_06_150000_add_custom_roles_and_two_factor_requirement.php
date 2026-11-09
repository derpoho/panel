<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business workspaces can define their own roles from the workspace
        // permissions. A member or invitation with one still keeps a base
        // role, which applies if the workspace leaves Business.
        Schema::create('workspace_custom_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('description', 160)->nullable();
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::table('user_workspace', function (Blueprint $table) {
            $table->foreignId('custom_role_id')->nullable()->after('role')
                ->constrained('workspace_custom_roles')->nullOnDelete();
        });

        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->foreignId('custom_role_id')->nullable()->after('role')
                ->constrained('workspace_custom_roles')->nullOnDelete();
        });

        // Members must have two-factor sign-in (or a passkey) to use the workspace.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('require_two_factor'));
        Schema::table('workspace_invitations', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_role_id'));
        Schema::table('user_workspace', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_role_id'));
        Schema::dropIfExists('workspace_custom_roles');
    }
};
