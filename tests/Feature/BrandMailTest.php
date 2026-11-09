<?php

use App\Mail\StatusPageUpdate;
use App\Mail\StatusSubscribeConfirm;
use App\Models\StatusPage;
use App\Models\StatusSubscriber;
use App\Models\User;
use App\Notifications\MonitorAlert;
use App\Notifications\WeeklySummaryMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

function brandUser(): User
{
    return new User(['name' => 'Sam Carter', 'email' => 'sam@acme.dev']);
}

test('the account emails are the designed ones', function () {
    $verify = (string) (new VerifyEmail)->toMail(User::factory()->unverified()->create())->render();
    expect($verify)->toContain('Confirm your email')
        ->toContain('images/email/verify.jpg')
        ->toContain('images/email/icon-verify.png')
        ->toContain('The link works for an hour.');

    $reset = (new ResetPassword('tok123'))->toMail(brandUser());
    expect($reset->subject)->toBe('Reset your ServerOS password')
        ->and((string) $reset->render())->toContain('Reset your password')->toContain('reset-password/tok123')->toContain('images/email/reset.jpg');
});

test('alerts are red when something’s wrong and green when it’s fixed', function () {
    $down = (string) (new MonitorAlert('Shop is down', ['https://shop.test', 'Timed out.'], 'https://acme.test/monitors/1', true, 'Open the monitor', 1))->toMail(brandUser())->render();
    $up = (string) (new MonitorAlert('Shop is back up', ['https://shop.test', 'Back after 3 minutes down.'], 'https://acme.test/monitors/1', false, 'Open the monitor', 1))->toMail(brandUser())->render();

    expect($down)->toContain('images/email/down.jpg')->toContain('Timed out.')->toContain('Stop alert emails from this workspace')
        ->and($up)->toContain('images/email/up.jpg')->toContain('Back after 3 minutes down.');
});

test('the weekly summary puts each section in a table, uptime as name and figure', function () {
    $html = (string) (new WeeklySummaryMail('Acme', ['headline' => 'No downtime this week', 'sections' => [
        ['title' => 'Uptime', 'lines' => ['Shop: 99.9% up, down 1 time for 2 minutes']],
        ['title' => 'Needs attention', 'lines' => ['Nothing right now.']],
    ]], 'https://acme.test', 1))->toMail(brandUser())->render();

    expect($html)->toContain('No downtime this week')
        ->toContain('Shop')->toContain('99.9% up, down 1 time for 2 minutes')
        ->toContain('#FCD34D')
        ->toContain('Stop the weekly summary');
});

test('status page emails carry the page’s name, not ours, and say where things stand', function () {
    $page = new StatusPage(['slug' => 'acme']);
    $subscriber = (new StatusSubscriber(['email' => 'sam@acme.dev']))->forceFill(['token' => 'tok'])->setRelation('subscribable', $page);

    $confirm = (new StatusSubscribeConfirm($subscriber, 'Acme status'))->render();
    $update = (new StatusPageUpdate($subscriber, 'Acme status', 'Checkout errors', 'Checkout errors', 'Resolved', 'All fixed.'))->render();

    expect($confirm)->toContain('Confirm updates')->toContain('images/email/subscribe.jpg')->toContain('Sent by ServerOS for Acme status.')
        ->not->toContain('One panel for the servers you already rent')
        ->and($update)->toContain('images/email/status.jpg')->toContain('Resolved')->toContain('#34D399')->toContain('All fixed.')->toContain('Unsubscribe');
});
