<?php

namespace App\Support\Auth;

/**
 * Throwaway inboxes: someone signs up with one to get past email
 * verification and walk away. Caught by domain (a list, subdomains
 * included) and, for services that rotate through many domains, by the
 * mail servers those domains receive on.
 */
class DisposableEmail
{
    /** Mail servers only throwaway inbox services use. */
    public const MAIL_SERVERS = [
        'mail.tm',            // maxxspace.com and the rest of mail.tm's domains
        'bumpemail.com',      // esiix.com and friends
        'mailinator.com',
        'guerrillamail.com',  // sharklasers.com, grr.la and friends
        'yopmail.com',
        'maildrop.cc',
        '1secmail.com',
        'mailnesia.com',
        'dispostable.com',
    ];

    /** @var array<string, true>|null */
    private static ?array $domains = null;

    public function __construct(private readonly MailExchangers $exchangers) {}

    public function is(string $email): bool
    {
        $domain = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '') {
            return false;
        }

        if (self::listed($domain)) {
            return true;
        }

        foreach ($this->exchangers->for($domain) as $host) {
            foreach (self::MAIL_SERVERS as $server) {
                if ($host === $server || str_ends_with($host, '.'.$server)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function listed(string $domain): bool
    {
        self::$domains ??= array_fill_keys(array_filter(array_map('trim', file(resource_path('data/disposable-email-domains.txt')) ?: []), fn (string $line) => $line !== '' && ! str_starts_with($line, '#')), true);

        // The domain itself or any parent: mail.yopmail.com is yopmail.com.
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            if (isset(self::$domains[implode('.', $parts)])) {
                return true;
            }
            array_shift($parts);
        }

        return false;
    }
}
