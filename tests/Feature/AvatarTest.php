<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('users can upload a profile picture', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)])
        ->assertSessionHasNoErrors();

    $user->refresh();
    Storage::disk('public')->assertExists($user->avatar_path);
    expect($user->avatar)->toEndWith($user->avatar_path)
        ->and($user->toArray())->toHaveKey('avatar')->not->toHaveKey('avatar_path');
});

test('uploading again replaces the old picture', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('one.jpg')]);
    $first = $user->refresh()->avatar_path;

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('two.jpg')]);

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($user->refresh()->avatar_path);
});

test('users can remove their profile picture', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.jpg')]);
    $path = $user->refresh()->avatar_path;

    $this->actingAs($user)->delete(route('profile.avatar.destroy'));

    Storage::disk('public')->assertMissing($path);
    expect($user->refresh()->avatar)->toBeNull();
});

test('profile pictures must be small images', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create())
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('huge.jpg')->size(6000)])
        ->assertSessionHasErrors('avatar');
});
