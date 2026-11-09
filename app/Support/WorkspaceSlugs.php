<?php

namespace App\Support;

use App\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates, generates and suggests workspace slugs, which double as the
 * workspace's subdomain ({slug}.serveros.com).
 */
class WorkspaceSlugs
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 32;

    public const string PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /**
     * Subdomains that must never belong to a customer workspace.
     *
     * @var list<string>
     */
    public const array RESERVED = [
        'about', 'account', 'accounts', 'admin', 'administrator', 'api', 'app', 'apps',
        'assets', 'auth', 'billing', 'blog', 'cdn', 'console', 'dashboard', 'dev',
        'docs', 'email', 'feedback', 'ftp', 'help', 'home', 'imap', 'internal', 'login', 'logout',
        'mail', 'my', 'ns1', 'ns2', 'panel', 'pop', 'portal', 'register', 'root',
        'security', 'serveros', 'settings', 'shop', 'signin', 'signup', 'smtp',
        'staging', 'static', 'status', 'store', 'support', 'system', 'test', 'www',
        'workspace', 'workspaces',
    ];

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            'regex:'.self::PATTERN,
            Rule::notIn(self::RESERVED),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Workspace::where('slug', $value)->exists()) {
                    return;
                }

                $suggestions = $this->suggest($value, 2);

                $fail($suggestions->isEmpty()
                    ? 'That workspace name is taken.'
                    : 'That workspace name is taken. Try '.$suggestions->join(', ', ' or ').'.');
            },
        ];
    }

    /**
     * Turn free text ("Northwind Dev!") into slug form ("northwind-dev").
     */
    public function normalize(string $value): string
    {
        $slug = Str::slug(Str::ascii($value));

        return trim(Str::limit($slug, self::MAX_LENGTH, ''), '-');
    }

    /**
     * Whether the slug is well formed, not reserved and not already taken.
     */
    public function isAvailable(string $slug): bool
    {
        return $this->problemWith($slug) === null;
    }

    /**
     * Explain why a slug can't be used, or null when it is free.
     */
    public function problemWith(string $slug): ?string
    {
        if (strlen($slug) < self::MIN_LENGTH || strlen($slug) > self::MAX_LENGTH) {
            return 'Use between '.self::MIN_LENGTH.' and '.self::MAX_LENGTH.' characters.';
        }

        if (! preg_match(self::PATTERN, $slug)) {
            return 'Use lowercase letters, numbers and hyphens, starting and ending with a letter or number.';
        }

        if (in_array($slug, self::RESERVED, true)) {
            return 'That name is reserved.';
        }

        if (Workspace::where('slug', $slug)->exists()) {
            return 'That workspace name is taken.';
        }

        return null;
    }

    /**
     * Generate a free slug for someone who didn't pick one, based on their name
     * or, failing that, their email address.
     */
    public function generate(string $name, string $email = ''): string
    {
        $base = $this->baseFrom($name, $email);

        if ($this->isAvailable($base)) {
            return $base;
        }

        return $this->suggest($base, 1)->first() ?? $this->randomSlug();
    }

    /**
     * Suggest free alternatives to a slug, the way email providers do when an
     * address is taken.
     *
     * @return Collection<int, string>
     */
    public function suggest(string $value, int $count = 3): Collection
    {
        $base = $this->normalize($value);

        if (strlen($base) < self::MIN_LENGTH) {
            $base = trim($base.'-workspace', '-');
        }

        $suggestions = collect();

        for ($attempt = 0; $attempt < 5 && $suggestions->count() < $count; $attempt++) {
            $candidates = $this->candidatesFor($base, $attempt)
                ->reject(fn (string $slug) => $suggestions->contains($slug))
                ->filter(fn (string $slug) => strlen($slug) <= self::MAX_LENGTH
                    && preg_match(self::PATTERN, $slug)
                    && ! in_array($slug, self::RESERVED, true))
                ->unique()
                ->values();

            $taken = Workspace::whereIn('slug', $candidates)->pluck('slug');

            $suggestions = $suggestions->merge($candidates->diff($taken));
        }

        return $suggestions->take($count)->values();
    }

    /**
     * Candidate slugs for a base: friendly suffixes first, then numbers.
     *
     * @return Collection<int, string>
     */
    private function candidatesFor(string $base, int $attempt): Collection
    {
        $friendly = $attempt === 0 ? ['hq', 'team', 'labs', 'ops', 'cloud'] : [];

        $numbered = collect(range(1, 4))->map(
            fn () => (string) random_int(10 ** ($attempt + 1), 10 ** ($attempt + 2) - 1),
        );

        return collect($friendly)
            ->merge($attempt === 0 ? [now()->format('Y')] : [])
            ->merge($numbered)
            ->map(fn (string $suffix) => $this->withSuffix($base, $suffix));
    }

    /**
     * Append a suffix, trimming the base so the result fits the length limit.
     */
    private function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - strlen($suffix) - 1;

        return trim(substr($base, 0, $room), '-').'-'.$suffix;
    }

    private function baseFrom(string $name, string $email): string
    {
        $base = $this->normalize($name);

        if (strlen($base) < self::MIN_LENGTH) {
            $base = $this->normalize(Str::before($email, '@'));
        }

        if (strlen($base) < self::MIN_LENGTH || in_array($base, self::RESERVED, true)) {
            $base = trim($base.'-workspace', '-');
        }

        return $base;
    }

    /**
     * A last-resort random slug.
     */
    private function randomSlug(): string
    {
        return 'workspace-'.Str::lower(Str::random(8));
    }
}
