<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Plain throttles count per person per route, so polling one route doesn't
 * use up another's allowance (the install command would answer 429 on
 * every try).
 */
beforeEach(function () {
    Route::middleware(['web', 'throttle:60,1'])->get('/__throttle/poll', fn () => 'ok')->name('test.throttle.poll');
    Route::middleware(['web', 'throttle:3,1'])->post('/__throttle/make', fn () => 'ok')->name('test.throttle.make');
});

test('polling one throttled route leaves another route’s allowance alone', function () {
    $user = User::factory()->create();

    foreach (range(1, 20) as $_) {
        $this->actingAs($user)->get('/__throttle/poll')->assertOk();
    }

    $this->actingAs($user)->post('/__throttle/make')->assertOk();
});

test('each route still keeps to its own limit, per person', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $_) {
        $this->actingAs($user)->post('/__throttle/make')->assertOk();
    }
    $this->actingAs($user)->post('/__throttle/make')->assertTooManyRequests();

    $this->actingAs(User::factory()->create())->post('/__throttle/make')->assertOk();
});
