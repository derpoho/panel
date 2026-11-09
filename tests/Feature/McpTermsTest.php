<?php

use App\Models\User;

test('accepting the MCP terms records when, once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('mcp-terms.accept'), ['accepted' => true])
        ->assertRedirect('/dashboard');

    $first = $user->fresh()->mcp_terms_accepted_at;
    expect($first)->not->toBeNull();

    $this->travel(1)->day();
    $this->actingAs($user)->post(route('mcp-terms.accept'), ['accepted' => true]);

    expect($user->fresh()->mcp_terms_accepted_at->equalTo($first))->toBeTrue();
});

test('the MCP terms must be explicitly accepted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('mcp-terms.accept'), [])
        ->assertSessionHasErrors('accepted');

    expect($user->fresh()->mcp_terms_accepted_at)->toBeNull();
});

test('guests cannot accept the MCP terms', function () {
    $this->post(route('mcp-terms.accept'), ['accepted' => true])
        ->assertRedirect(route('login'));
});
