<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('deleting your account takes the workspaces only you are in with it', function () {
    $workspace = Workspace::factory()->create();
    $user = $workspace->owner;

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertGuest();
    expect($user->fresh())->toBeNull()
        ->and($workspace->fresh())->toBeNull();
});

test('you can’t delete your account while you own a workspace others are in', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $workspace->members()->attach(User::factory()->create(), ['role' => 'member']);
    $user = $workspace->owner;

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('account');

    expect($user->fresh())->not->toBeNull()
        ->and($workspace->fresh())->not->toBeNull();

    // The profile page says so, and why.
    $this->actingAs($user)->get('https://'.$workspace->host().'/settings/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('deleteAccount.blocking', ['Acme'])
            ->where('deleteAccount.hasPassword', true));
});

test('an account with no password confirms by typing its email', function () {
    $user = User::factory()->create(['password_set_at' => null]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['confirmation' => 'not-it@example.com'])
        ->assertSessionHasErrors('confirmation');
    expect($user->fresh())->not->toBeNull();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['confirmation' => $user->email])
        ->assertSessionHasNoErrors();
    expect($user->fresh())->toBeNull();
});
