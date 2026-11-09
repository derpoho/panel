<?php

namespace App\Support;

/**
 * Who runs this install, for the small print on its pages and in its
 * emails (config serveros.brand). A self-hosted install names no company
 * and links no terms unless it's told to; a package can fill them in.
 */
final class Brand
{
    /** The company that runs this install, like "ServerOS Cloud Ltd", if it's set. */
    public static function company(): ?string
    {
        return self::filled('company');
    }

    /** Who an email signs off as: the company, or else the app's name. */
    public static function signOff(): string
    {
        return self::company() ?? (string) config('app.name');
    }

    /** The company's postal address, under the documents staff send. */
    public static function address(): ?string
    {
        return self::filled('address');
    }

    /** What emails call the panel's address, like "serveros.com": its host unless set. */
    public static function website(): string
    {
        return self::filled('website') ?? (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'));
    }

    /**
     * The git author of the commits a workflow's coding agent makes, unless
     * it pushes as the GitHub App: kept to what's safe inside the agent's
     * shell script.
     */
    public static function agentEmail(): string
    {
        $email = self::filled('agent_email') ?? 'agent@'.config('app.domain');

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/^[A-Za-z0-9._%+@-]+$/', $email) === 1
            ? $email
            : 'agent@localhost';
    }

    /**
     * What every page gets: who to credit in the footers, where the terms and
     * privacy policy are, and where the panel's code is.
     *
     * @return array{company: string|null, terms: string|null, privacy: string|null, source: string|null}
     */
    public static function shared(): array
    {
        return [
            'company' => self::company(),
            'terms' => self::filled('terms_url'),
            'privacy' => self::filled('privacy_url'),
            'source' => self::filled('source_url'),
        ];
    }

    private static function filled(string $key): ?string
    {
        $value = config("serveros.brand.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
