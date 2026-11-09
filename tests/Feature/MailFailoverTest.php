<?php

use App\Mail\TestEmail;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

test('mail tries Resend first, then Brevo', function () {
    expect(config('mail.mailers.failover.mailers'))->toBe(['resend', 'brevo'])
        ->and(config('mail.mailers.brevo.host'))->toBe('smtp-relay.brevo.com');
});

test('when the first mailer refuses, like Resend over its quota, the next one sends', function () {
    Mail::extend('over-quota', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('daily_quota_exceeded');
        }

        public function __toString(): string
        {
            return 'over-quota';
        }
    });
    $backup = new ArrayTransport;
    Mail::extend('backup', fn () => $backup);
    config()->set('mail.mailers.quota', ['transport' => 'over-quota']);
    config()->set('mail.mailers.backup', ['transport' => 'backup']);
    config()->set('mail.mailers.failover.mailers', ['quota', 'backup']);
    config()->set('mail.default', 'failover');

    Mail::to('someone@example.com')->send(new TestEmail);

    expect($backup->messages())->toHaveCount(1);
});
