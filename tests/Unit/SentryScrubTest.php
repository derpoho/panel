<?php

use App\Support\Sentry\Scrub;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

it('masks secrets, emails and SQL values in text', function () {
    $text = Scrub::text('SQLSTATE[23505]: dup (Connection: pgsql, SQL: insert into "users" ("email", "id") values (sam@example.com, 42))');
    expect($text)->not->toContain('sam@example.com')->not->toContain('42')->toContain('insert into "users"');

    expect(Scrub::text('password=hunter2 and token: abc.def'))->toBe('password=[filtered] and token: [filtered]')
        ->and(Scrub::text('Authorization: Bearer abc123.xyz'))->not->toContain('abc123')
        ->and(Scrub::text('key sosd_'.str_repeat('a', 20).' here'))->toBe('key [filtered] here')
        ->and(Scrub::text('mail sam@example.com failed'))->toBe('mail [email] failed')
        ->and(Scrub::text('Undefined variable $machine in Show.php'))->toBe('Undefined variable $machine in Show.php');
});

it('drops request data and the user, and scrubs exceptions, breadcrumbs and extra', function () {
    $event = Event::createEvent();
    $event->setRequest(['method' => 'POST', 'url' => 'https://serveros.com/login?next=/x', 'data' => ['password' => 'hunter2'], 'cookies' => ['s' => '1']]);
    $event->setUser(UserDataBag::createFromUserIdentifier('7'));
    $exception = new ExceptionDataBag(new RuntimeException('x'));
    $exception->setValue('token=supersecretvalue');
    $event->setExceptions([$exception]);
    $event->setBreadcrumb([new Breadcrumb('info', 'default', 'db.sql', 'select 1 where email = \'a@b.co\'', ['bindings' => ['a@b.co']])]);
    $event->setExtra(['api_key' => 'k', 'note' => 'from a@b.co']);

    $out = Scrub::beforeSend($event);

    expect($out->getRequest())->toBe(['method' => 'POST', 'url' => 'https://serveros.com/login'])
        ->and($out->getUser())->toBeNull()
        ->and($out->getExceptions()[0]->getValue())->toBe('token=[filtered]')
        ->and($out->getBreadcrumbs()[0]->getMetadata())->toBe(['bindings' => '[filtered]'])
        ->and($out->getBreadcrumbs()[0]->getMessage())->not->toContain('a@b.co')
        ->and($out->getExtra())->toBe(['api_key' => '[filtered]', 'note' => 'from [email]']);
});
