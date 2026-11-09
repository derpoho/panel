<?php

use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('unknown pages get our 404, with the right way back', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/Error')
            ->where('status', 404)
            ->where('home.label', 'Go to the homepage'));

    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->where('home.label', 'Back to dashboard'));

    // JSON callers still get JSON.
    $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
});

test('the error page and suspended accounts name this install’s support address', function () {
    config()->set('mail.support.address', 'help@example.com');

    $this->get('/no-such-page')->assertInertia(fn (Assert $page) => $page->where('support', 'help@example.com'));

    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['suspended_at' => now()])->save();
    $this->actingAs($workspace->owner)->get('/dashboard')
        ->assertSessionHas('status', 'This account has been suspended. If you think that’s a mistake, email help@example.com.');
});
