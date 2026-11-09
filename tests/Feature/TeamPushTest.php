<?php

use App\Jobs\SendNtfy;
use App\Support\TeamPush;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a push goes to the ntfy topic as JSON, with the token when there is one', function () {
    config()->set('services.ntfy.server', 'https://ntfy.example.com/');
    config()->set('services.ntfy.topic', 'serveros-team');
    config()->set('services.ntfy.token', 'tk_secret');
    Http::fake(['ntfy.example.com' => Http::response(['id' => 'abc'])]);

    (new SendNtfy('Payment received', '£12.00 for a plan', 'https://serveros.test/admin/workspaces/1', 'moneybag'))->handle();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://ntfy.example.com'
        && $request->hasHeader('Authorization', 'Bearer tk_secret')
        && $request->data() === [
            'topic' => 'serveros-team',
            'title' => 'Payment received',
            'message' => '£12.00 for a plan',
            'click' => 'https://serveros.test/admin/workspaces/1',
            'tags' => ['moneybag'],
        ]);
});

test('nothing is pushed until a topic is set', function () {
    Queue::fake([SendNtfy::class]);

    config()->set('services.ntfy.topic', null);
    TeamPush::send('New signup', 'Sam (sam@example.com)');
    Queue::assertNothingPushed();

    config()->set('services.ntfy.topic', 'serveros-team');
    TeamPush::send('New signup', 'Sam (sam@example.com)');
    Queue::assertPushed(SendNtfy::class, 1);
});

test('a new signup is pushed to the team', function () {
    config()->set('services.ntfy.topic', 'serveros-team');
    Queue::fake([SendNtfy::class]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Queue::assertPushed(SendNtfy::class, fn (SendNtfy $push) => $push->title === 'New signup'
        && $push->message === 'A new user signed up');
});
