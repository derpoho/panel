<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\Workspace;

test('an open dashboard can ask which build is live', function () {
    $workspace = Workspace::factory()->create();
    $url = 'https://'.$workspace->host().'/panel-version';

    $this->actingAs($workspace->owner)->getJson($url)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['version' => app(HandleInertiaRequests::class)->version(request())]);

    // Only for people in the workspace.
    $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
});
