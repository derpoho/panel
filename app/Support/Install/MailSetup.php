<?php

namespace App\Support\Install;

use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The installer's email step: SMTP settings tried with a real email before
 * they're saved, errors in plain words, and what they become in .env.
 */
final class MailSetup
{
    /** The settings keys this step owns. */
    public const KEYS = ['MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'];

    /** Only for the test email. */
    private const MAILER = 'serveros_install';

    /**
     * Send a test email with these settings: null when it went, otherwise
     * what's wrong.
     *
     * @param  array{host: string, port: int, username: string|null, password: string|null, from_address: string, from_name: string|null}  $settings
     */
    public function test(array $settings, string $to): ?string
    {
        config(['mail.mailers.'.self::MAILER => [
            'transport' => 'smtp',
            'scheme' => self::scheme($settings['port']),
            'host' => $settings['host'],
            'port' => $settings['port'],
            'username' => $settings['username'],
            'password' => $settings['password'],
            'timeout' => 10,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ]]);
        Mail::purge(self::MAILER);

        try {
            Mail::mailer(self::MAILER)->raw(
                "This is a test from your new ServerOS panel. Email works, so invitations, alerts and password resets will arrive.\n\n".config('app.url'),
                fn ($message) => $message->to($to)
                    ->from($settings['from_address'], $settings['from_name'] ?: 'ServerOS')
                    ->subject('ServerOS can send email'),
            );

            return null;
        } catch (Throwable $e) {
            return self::plainError($e, $settings);
        } finally {
            Mail::purge(self::MAILER);
        }
    }

    /**
     * @param  array{host: string, port: int, username: string|null, password: string|null, from_address: string, from_name: string|null}  $settings
     * @return array<string, string>
     */
    public static function envValues(array $settings): array
    {
        return [
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => self::scheme($settings['port']) ?? 'null',
            'MAIL_HOST' => $settings['host'],
            'MAIL_PORT' => (string) $settings['port'],
            'MAIL_USERNAME' => (string) $settings['username'],
            'MAIL_PASSWORD' => (string) $settings['password'],
            'MAIL_FROM_ADDRESS' => $settings['from_address'],
            'MAIL_FROM_NAME' => (string) ($settings['from_name'] ?: 'ServerOS'),
        ];
    }

    /** Port 465 is TLS from the start; the rest start plain and upgrade (STARTTLS). */
    private static function scheme(int $port): ?string
    {
        return $port === 465 ? 'smtps' : null;
    }

    /** @param  array{host: string, port: int, password: string|null, from_address: string}  $settings */
    public static function plainError(Throwable $e, array $settings): string
    {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $where = $settings['host'].':'.$settings['port'];

        $plain = match (true) {
            str_contains($lower, '535') || str_contains($lower, 'authentication') || str_contains($lower, 'credentials') => 'The mail server turned down that username and password.',
            str_contains($lower, 'connection could not be established') || str_contains($lower, 'connection refused') || str_contains($lower, 'timed out') || str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service not known') => "Couldn’t reach a mail server at {$where}. Check the host and port, and that this server may connect out on that port (some hosts block 25 and 587).",
            str_contains($lower, 'tls') || str_contains($lower, 'ssl') || str_contains($lower, 'certificate') => "Couldn’t start a secure connection with {$where}. Try port 587, or 465 if the server wants TLS from the start.",
            str_contains($lower, 'sender') || (str_contains($lower, 'from') && str_contains($lower, '55')) => 'The mail server won’t send from '.$settings['from_address'].'. Use an address it lets you send from.',
            default => null,
        };
        if ($plain !== null) {
            return $plain;
        }

        $password = (string) $settings['password'];
        $first = strtok($message, "\n") ?: $message;

        return 'It didn’t send: '.($password !== '' ? str_replace($password, '••••', $first) : $first);
    }
}
