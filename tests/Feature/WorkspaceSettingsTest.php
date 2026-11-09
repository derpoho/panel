<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function settingsUrl(Workspace $workspace, string $path = 'general'): string
{
    return 'https://'.$workspace->host().'/settings/'.$path;
}

function addMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

test('settings redirects to the general tab', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get('https://'.$workspace->host().'/settings')
        ->assertRedirect(settingsUrl($workspace));
});

test('admins can rename the workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Old']);
    $admin = addMember($workspace, 'admin');

    $this->actingAs($admin)
        ->get(settingsUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/General')
            ->where('canManageSettings', true)
            ->where('workspaceAccess.role', 'admin'));

    $this->actingAs($admin)
        ->patch(settingsUrl($workspace), ['name' => 'Northwind'])
        ->assertSessionHasNoErrors();

    expect($workspace->refresh()->name)->toBe('Northwind');
});

test('members cannot rename the workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Old']);
    $member = addMember($workspace, 'member');

    $this->actingAs($member)
        ->get(settingsUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageSettings', false)
            ->where('workspaceAccess.permissions', ['manage-machines']));

    $this->actingAs($member)
        ->patch(settingsUrl($workspace), ['name' => 'Hijacked'])
        ->assertForbidden();
});

test('owners can upload and remove a workspace logo', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->post(settingsUrl($workspace, 'logo'), ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)])
        ->assertSessionHasNoErrors();

    $path = $workspace->refresh()->logo_path;
    Storage::disk('public')->assertExists($path);
    expect($workspace->toPageProps()['logoUrl'])->toEndWith($path);

    $this->actingAs($workspace->owner)->delete(settingsUrl($workspace, 'logo'));

    Storage::disk('public')->assertMissing($path);
    expect($workspace->refresh()->logo_path)->toBeNull();
});

test('logos must be images', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->post(settingsUrl($workspace, 'logo'), ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('logo');
});

test('viewers cannot change the logo', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();

    $this->actingAs(addMember($workspace, 'viewer'))
        ->post(settingsUrl($workspace, 'logo'), ['logo' => UploadedFile::fake()->image('logo.png')])
        ->assertForbidden();
});

test('the profile tab renders inside the workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(settingsUrl($workspace, 'profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/Profile'));
});

test('owners can delete a workspace by typing its address', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'logo_path' => 'workspace-logos/acme.png']);
    Storage::disk('public')->put('workspace-logos/acme.png', 'logo');
    $member = addMember($workspace, 'admin');
    Machine::factory()->for($workspace)->create();

    $this->actingAs($workspace->owner)
        ->get(settingsUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('canDelete', true));

    $this->actingAs($workspace->owner)
        ->withHeaders(['X-Inertia' => 'true'])
        ->delete('https://'.$workspace->host().'/settings/workspace', ['confirmation' => 'acme'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', rtrim(config('app.url'), '/').'/workspaces');

    expect(Workspace::find($workspace->id))->toBeNull()
        ->and(Machine::where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and($member->workspaces()->count())->toBe(0);
    Storage::disk('public')->assertMissing('workspace-logos/acme.png');
});

test('deleting needs the exact address', function () {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);

    $this->actingAs($workspace->owner)
        ->delete('https://'.$workspace->host().'/settings/workspace', ['confirmation' => 'Acme'])
        ->assertSessionHasErrors(['confirmation' => 'That doesn’t match. Type acme to confirm.']);

    expect(Workspace::find($workspace->id))->not->toBeNull();
});

test('only the owner can delete a workspace', function () {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = addMember($workspace, 'admin');

    $this->actingAs($admin)
        ->get(settingsUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('canDelete', false));

    $this->actingAs($admin)
        ->delete('https://'.$workspace->host().'/settings/workspace', ['confirmation' => 'acme'])
        ->assertForbidden();

    expect(Workspace::find($workspace->id))->not->toBeNull();
});
