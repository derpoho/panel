<?php

namespace App\Events;

/**
 * Someone asked us to stop emailing an address, with the link in an email
 * (Unsubscribes::add), the address normalised. Listeners run before the
 * page says it's done.
 */
final readonly class EmailUnsubscribed
{
    public function __construct(public string $email) {}
}
