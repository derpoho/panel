<?php

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function inviteUrl(Workspace $workspace): string
{
    return 'https://'.$workspace->host().'/settings/invitations';
}

test('admins can invite people by email with a role', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create(['name' => 'Northwind']);

    $this->actingAs($workspace->owner)
        ->post(inviteUrl($workspace), ['email' => ' Sam@Example.com ', 'role' => 'viewer'])
        ->assertSessionHasNoErrors();

    $invitation = $workspace->invitations()->sole();
    expect($invitation->email)->toBe('sam@example.com')
        ->and($invitation->role->value)->toBe('viewer')
        ->and($invitation->inviter->is($workspace->owner))->toBeTrue();

    Notification::assertSentOnDemand(WorkspaceInvitationSent::class, function (WorkspaceInvitationSent $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation) {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'sam@example.com'
            && $mail->subject === 'Join Northwind on ServerOS'
            && str_starts_with($notification->acceptUrl(), rtrim(config('app.url'), '/').'/invitations/')
            && WorkspaceInvitation::findByToken($notification->plainToken)?->is($invitation);
    });
});

test('inviting the same email again refreshes the invitation', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post(inviteUrl($workspace), ['email' => 'sam@example.com', 'role' => 'viewer']);
    $firstToken = $workspace->invitations()->sole()->token;

    $this->actingAs($workspace->owner)->post(inviteUrl($workspace), ['email' => 'sam@example.com', 'role' => 'admin']);

    $invitation = $workspace->invitations()->sole();
    expect($invitation->token)->not->toBe($firstToken)
        ->and($invitation->role->value)->toBe('admin');
    Notification::assertSentOnDemandTimes(WorkspaceInvitationSent::class, 2);
});

test('existing members cannot be invited', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->post(inviteUrl($workspace), ['email' => $workspace->owner->email, 'role' => 'admin'])
        ->assertSessionHasErrors('email');
});

test('starter workspaces can only invite admins', function () {
    Notification::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Essential)->create();

    $this->actingAs($workspace->owner)
        ->post(inviteUrl($workspace), ['email' => 'sam@example.com', 'role' => 'member'])
        ->assertSessionHasErrors('role');

    $this->actingAs($workspace->owner)
        ->post(inviteUrl($workspace), ['email' => 'sam@example.com', 'role' => 'admin'])
        ->assertSessionHasNoErrors();
});

test('members cannot invite people', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->post(inviteUrl($workspace), ['email' => 'sam@example.com', 'role' => 'viewer'])
        ->assertForbidden();
});

test('invitations can be revoked, but only within their workspace', function () {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceInvitation::factory()->for($workspace)->create();
    $elsewhere = WorkspaceInvitation::factory()->create();

    $this->actingAs($workspace->owner)
        ->delete(inviteUrl($workspace)."/{$elsewhere->id}")
        ->assertNotFound();

    $this->actingAs($workspace->owner)->delete(inviteUrl($workspace)."/{$invitation->id}");

    expect(WorkspaceInvitation::find($invitation->id))->toBeNull()
        ->and(WorkspaceInvitation::find($elsewhere->id))->not->toBeNull();
});

test('the invitee can accept and lands in the workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Northwind']);
    $invitee = User::factory()->create(['email' => 'sam@example.com']);
    WorkspaceInvitation::factory()->for($workspace)->withToken('secret-token')->create(['email' => 'sam@example.com', 'role' => 'viewer']);

    $this->actingAs($invitee)
        ->get(route('invitations.show', 'secret-token'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AcceptInvitation')
            ->where('status', 'valid')
            ->where('invitation.workspace', 'Northwind')
            ->where('invitation.role', 'Viewer'));

    $this->actingAs($invitee)
        ->post(route('invitations.accept', 'secret-token'))
        ->assertRedirect($workspace->url());

    expect($workspace->roleOf($invitee)->value)->toBe('viewer')
        ->and($workspace->invitations()->count())->toBe(0);
});

test('invitations only work for the invited email', function () {
    $workspace = Workspace::factory()->create();
    WorkspaceInvitation::factory()->for($workspace)->withToken('secret-token')->create(['email' => 'sam@example.com']);
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)
        ->get(route('invitations.show', 'secret-token'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'wrong-account'));

    $this->actingAs($someoneElse)
        ->post(route('invitations.accept', 'secret-token'))
        ->assertForbidden();

    expect($workspace->hasMember($someoneElse))->toBeFalse();
});

test('expired and unknown invitations cannot be accepted', function () {
    $invitee = User::factory()->create(['email' => 'sam@example.com']);
    WorkspaceInvitation::factory()->expired()->withToken('old-token')->create(['email' => 'sam@example.com']);

    $this->actingAs($invitee)
        ->get(route('invitations.show', 'old-token'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'expired'));

    $this->actingAs($invitee)->post(route('invitations.accept', 'old-token'))->assertForbidden();

    $this->actingAs($invitee)
        ->get(route('invitations.show', 'nope'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'invalid')->where('invitation', null));
});

test('guests are sent to sign in and come back to the invitation after registering', function () {
    WorkspaceInvitation::factory()->withToken('secret-token')->create(['email' => 'sam@example.com']);

    $this->get(route('invitations.show', 'secret-token'))
        ->assertRedirect(rtrim(config('app.url'), '/').'/login');

    $this->post(route('register.store'), [
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', 'secret-token'));
});
