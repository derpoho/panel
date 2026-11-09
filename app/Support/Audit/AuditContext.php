<?php

namespace App\Support\Audit;

use App\Http\Middleware\TrustCloudflare;
use App\Models\User;
use App\Support\Admin\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where something being logged came from: the request it happened in (if
 * any), its IP and browser, and whether ServerOS staff did it while
 * viewing as someone. Activity, AdminAction and the jobs people start all
 * ask here, so they agree on who really did a thing.
 */
final class AuditContext
{
    /** What a workspace sees in place of the admin who acted for them. */
    public const string SUPPORT = 'ServerOS Support';

    private const string REQUEST_ID = 'audit.request_id';

    private const string RECORDED = 'audit.recorded';

    /**
     * What's running on someone's behalf right now, innermost last: an MCP
     * tool (`via` mcp, `client`) or a workflow's step (`via` workflow,
     * `workflow`, `run`). Entries written meanwhile carry it.
     *
     * @var list<array<string, mixed>>
     */
    private static array $via = [];

    /**
     * The View as session when `$user` is the account being viewed as
     * right now: what they "did" was really done by staff. Null for
     * anything else, including other people named in the same request.
     */
    public static function viewAsSession(?User $user): ?int
    {
        $request = self::request();
        if ($request === null || $user === null || $request->user()?->getAuthIdentifier() !== $user->id) {
            return null;
        }
        // A workflow's steps run as whoever saved it, whoever pressed Run.
        if ((self::via()['via'] ?? null) === 'workflow') {
            return null;
        }

        // Not whether the admin still is one: AccountGuard ends a session
        // whose admin isn't, and nothing in it is the customer's doing.
        return Impersonation::sessionId($request);
    }

    /**
     * Whether `$user` is the one signed in to the request being handled:
     * then its IP and browser are theirs. A webhook or a machine's daemon
     * crediting someone says nothing about where that person is.
     */
    public static function isRequestUser(User $user): bool
    {
        return self::request()?->user()?->getAuthIdentifier() === $user->id;
    }

    /** "ServerOS Support" in the reader's language. */
    public static function supportName(): string
    {
        return is_string($word = __(self::SUPPORT)) ? $word : self::SUPPORT;
    }

    /**
     * Run `$work` with English copy: what goes in the admin log is read by
     * staff later, so it mustn't come out in whatever language the request
     * happened to be in.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function inEnglish(callable $work): mixed
    {
        $locale = app()->getLocale();
        if ($locale === 'en') {
            return $work();
        }
        app()->setLocale('en');
        try {
            return $work();
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * How a page's address names someone whose account is gone: a hash of
     * their email, so the email itself stays out of it.
     */
    public static function goneKey(string $email): string
    {
        return 'gone-'.substr(hash_hmac('sha256', mb_strtolower($email), (string) config('app.key')), 0, 12);
    }

    /**
     * Run `$work` on someone's behalf (see $via), so what it records says
     * so: through MCP and which client, or as a workflow's step.
     *
     * @template T
     *
     * @param  array<string, mixed>  $via
     * @param  callable(): T  $work
     * @return T
     */
    public static function within(array $via, callable $work): mixed
    {
        self::$via[] = $via;
        try {
            return $work();
        } finally {
            array_pop(self::$via);
        }
    }

    /**
     * What's running on someone's behalf right now, if anything.
     *
     * @return array<string, mixed>
     */
    public static function via(): array
    {
        return self::$via === [] ? [] : self::$via[array_key_last(self::$via)];
    }

    /** The current View as session, whoever it's for. */
    public static function sessionId(): ?int
    {
        $request = self::request();

        return $request === null ? null : Impersonation::sessionId($request);
    }

    public static function ip(): ?string
    {
        return self::request()?->ip();
    }

    /** The IP's country as Cloudflare saw it (TrustCloudflare), or T1 for Tor. */
    public static function country(): ?string
    {
        $country = self::request()?->attributes->get(TrustCloudflare::COUNTRY);

        return is_string($country) ? $country : null;
    }

    public static function userAgent(): ?string
    {
        $agent = self::request()?->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }

    /** One id per request, so the entries it wrote can be found together. */
    public static function requestId(): ?string
    {
        $request = self::request();
        if ($request === null) {
            return null;
        }
        if (! $request->attributes->has(self::REQUEST_ID)) {
            $request->attributes->set(self::REQUEST_ID, (string) Str::uuid());
        }

        return $request->attributes->get(self::REQUEST_ID);
    }

    /** Note that this request wrote an Activity or admin log entry. */
    public static function markRecorded(): void
    {
        self::request()?->attributes->set(self::RECORDED, true);
    }

    /**
     * Note that this request had nothing worth recording (an edit that
     * changed nothing), so it counts as covered all the same.
     */
    public static function nothingChanged(): void
    {
        self::markRecorded();
    }

    /** Start counting afresh: one MCP request can run several tools. */
    public static function forgetRecorded(): void
    {
        self::request()?->attributes->remove(self::RECORDED);
    }

    /** Whether this request (or the one given) recorded something, or said it had nothing to. */
    public static function recorded(?Request $request = null): bool
    {
        return (bool) ($request ?? self::request())?->attributes->get(self::RECORDED, false);
    }

    /**
     * Where it came from: web, mcp, workflow (a step of a run), machine (a
     * report from a machine's daemon), api (webhooks and other calls) or
     * system (the scheduler and queue).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function source(array $meta = []): string
    {
        $request = self::request();

        return match (true) {
            // Ask AI runs the same tools as MCP, so it's filed with them.
            in_array($meta['via'] ?? null, ['mcp', 'assistant'], true) => 'mcp',
            ($meta['via'] ?? null) === 'workflow' => 'workflow',
            $request === null => 'system',
            $request->is('api/gateway/*', 'api/daemon/*') => 'machine',
            $request->is('api/*', 'scim/*', 'oauth/token', 'oauth/revoke') => 'api',
            default => 'web',
        };
    }

    /** The HTTP request being handled, or null in a command or job. */
    private static function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }
        $request = app('request');

        return $request->route() !== null ? $request : null;
    }
}
