<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

test('admin on a workspace subdomain goes to the main domain, path and query kept', function () {
    // An admin page like the ones a package adds, which aren't tied to a host.
    Route::middleware(['web', 'auth', 'verified', 'can:viewAdmin'])->get('admin/test-page', fn () => 'Admin');
    $admin = User::factory()->admin()->create();
    $workspace = Workspace::factory()->create();

    $this->actingAs($admin)->get('https://'.$workspace->host().'/admin/test-page?q=ada')
        ->assertRedirect(rtrim(config('app.url'), '/').'/admin/test-page?q=ada');

    $this->actingAs($admin)->get(rtrim(config('app.url'), '/').'/admin/test-page')->assertOk();
});

test('leaving View as works from the workspace being viewed', function () {
    $admin = User::factory()->admin()->create();
    $workspace = Workspace::factory()->create();
    $sam = $workspace->owner;

    $this->actingAs($admin)->post(viewAsUrl($sam), ['reason' => 'Checking']);
    $this->post('https://'.$workspace->host().'/admin/return')
        ->assertRedirect(rtrim(config('app.url'), '/')."/admin/users/{$sam->id}");
    $this->assertAuthenticatedAs($admin);
});
