<?php

namespace App\Support\Audit;

use App\Models\AccountEvent;
use App\Support\Extensions\Extensions;

/**
 * How an account event reads, on Settings › Security (to the person, in
 * their language: "Changed your password") and on the admin pages (in
 * English, about them: "Changed their password").
 */
final class AccountEventLine
{
    public static function text(AccountEvent $e, bool $staff = false): string
    {
        $meta = $e->meta ?? [];
        $provider = fn (?string $key): string => match ($key) {
            'github' => 'GitHub',
            'google' => 'Google',
            default => (string) $key,
        };

        [$text, $values] = match ($e->event) {
            'signin' => self::signIn($e->method, (bool) ($meta['two_factor'] ?? false), $provider($e->method)),
            'account.created' => match (true) {
                in_array($e->method, ['google', 'github'], true) => ['Created your account with :provider', ['provider' => $provider($e->method)]],
                $e->method === 'sso' => ['Created your account with SSO', []],
                default => ['Created your account', []],
            },
            'signin.failed' => [($meta['reason'] ?? null) === 'code' ? 'Sign-in failed: wrong two-factor code' : 'Sign-in failed: wrong password', []],
            'signin.locked_out' => ['Sign-in paused after too many wrong tries', []],
            'signout' => ['Signed out', []],
            'password.changed' => ['Changed your password', []],
            'password.set' => ['Set a password', []],
            'password.reset' => ['Reset your password from an email link', []],
            'password.reset_requested' => ['Password reset link sent', []],
            'email.verified' => ['Verified your email address', []],
            'email.changed' => ['Changed your email to :email', ['email' => $meta['email'] ?? '']],
            'name.changed' => ['Changed your name to :name', ['name' => $meta['name'] ?? '']],
            'avatar.changed' => ['Changed your profile picture', []],
            'avatar.removed' => ['Removed your profile picture', []],
            'two_factor.on' => ['Turned on two-factor sign-in', []],
            'two_factor.off' => ['Turned off two-factor sign-in', []],
            'two_factor.recovery_codes' => ['Made new recovery codes', []],
            'passkey.added' => ['Added the passkey :name', ['name' => $meta['name'] ?? '']],
            'passkey.removed' => ['Removed the passkey :name', ['name' => $meta['name'] ?? '']],
            'social.connected' => ['Connected :provider', ['provider' => $provider($meta['provider'] ?? null)]],
            'social.disconnected' => ['Disconnected :provider', ['provider' => $provider($meta['provider'] ?? null)]],
            'sso.linked' => ['Linked your account to :connection at :workspace', ['connection' => $meta['connection'] ?? '', 'workspace' => $meta['workspace'] ?? '']],
            'sessions.signed_out' => match ($meta['scope'] ?? 'everywhere') {
                'one' => ['Signed out :device', ['device' => $meta['device'] ?? __('a device')]],
                'others' => ['Signed out every other device', []],
                default => ['Signed out everywhere', []],
            },
            'mcp_terms.accepted' => ['Accepted the MCP terms', []],
            'account.deleted' => ['Deleted the account', []],
            'account.suspended' => ['Suspended your account', []],
            'account.unsuspended' => ['Lifted the suspension on your account', []],
            // A package's own event (Extensions::addAccountEvent), or its name.
            default => [app(Extensions::class)->accountEventText($e->event) ?? $e->event, []],
        };

        if ($staff) {
            $text = (string) preg_replace(['/\byour\b/', '/\byou\b/'], ['their', 'them'], $text);
            foreach ($values as $key => $value) {
                $text = str_replace(':'.$key, (string) $value, $text);
            }

            return $text;
        }

        $line = __($text, $values);

        return is_string($line) ? $line : $text;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function signIn(?string $method, bool $twoFactor, string $provider): array
    {
        return match (true) {
            $method === 'passkey' => ['Signed in with a passkey', []],
            $method === 'recovery_code' => ['Signed in with a recovery code', []],
            $method === 'sso' => ['Signed in with SSO', []],
            in_array($method, ['google', 'github'], true) => [$twoFactor ? 'Signed in with :provider and a two-factor code' : 'Signed in with :provider', ['provider' => $provider]],
            default => [$twoFactor ? 'Signed in with your password and a two-factor code' : 'Signed in with your password', []],
        };
    }

    /** good, warn, bad or neutral, for the dot beside it. */
    public static function tone(AccountEvent $e): string
    {
        return match ($e->event) {
            'signin.failed', 'signin.locked_out', 'two_factor.off', 'passkey.removed', 'social.disconnected' => 'warn',
            'account.suspended', 'account.deleted' => 'bad',
            'two_factor.on', 'passkey.added', 'account.unsuspended' => 'good',
            default => 'neutral',
        };
    }
}
