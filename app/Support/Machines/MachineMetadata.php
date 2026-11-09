<?php

namespace App\Support\Machines;

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\DaemonFacts;
use Illuminate\Validation\ValidationException;

/**
 * What a workspace knows about its machines beyond what the daemon finds:
 * the provider and region (filled in by detection until someone
 * sets them), a tier, and labels of its own ("customer: acme").
 *
 * Fields are named "provider", "region", "tier" or "label:<key>", the same
 * way in filters, groupings and policy rules.
 */
final class MachineMetadata
{
    /** @var list<string> */
    public const BUILT_IN = ['provider', 'region', 'tier'];

    public const MAX_LABELS = 20;

    public const MAX_KEY = 40;

    public const MAX_VALUE = 100;

    /** A machine's value for a field, or null when it has none. */
    public static function value(Machine $machine, string $field): ?string
    {
        if (str_starts_with($field, 'label:')) {
            $value = ($machine->labels ?? [])[substr($field, 6)] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        }

        $value = match ($field) {
            'provider' => $machine->provider,
            'region' => $machine->region,
            'tier' => $machine->tier,
            default => null,
        };

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Whether a field name is one we know: built in, or a label. */
    public static function validField(string $field): bool
    {
        return in_array($field, self::BUILT_IN, true)
            || (str_starts_with($field, 'label:') && self::validKey(substr($field, 6)));
    }

    public static function validKey(string $key): bool
    {
        return $key !== '' && mb_strlen($key) <= self::MAX_KEY && preg_match('/^[\pL\pN][\pL\pN _.\-\/]*$/u', $key) === 1;
    }

    /** Whether the machine's field holds this value (any case). */
    public static function matches(Machine $machine, string $field, string $value): bool
    {
        $mine = self::value($machine, $field);

        return $mine !== null && mb_strtolower($mine) === mb_strtolower(trim($value));
    }

    /**
     * Labels as they're stored: trimmed, empty values dropped, at most
     * MAX_LABELS. Throws for a key or value that can't be used.
     *
     * @param  array<array-key, mixed>  $labels  key => value
     * @return array<string, string>
     */
    public static function labels(array $labels, string $errorKey = 'labels'): array
    {
        $clean = [];
        foreach ($labels as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (! self::validKey($key)) {
                throw ValidationException::withMessages([$errorKey => __('Label names start with a letter or number, and use letters, numbers, spaces, dots, dashes and slashes, up to :max characters.', ['max' => self::MAX_KEY])]);
            }
            if (mb_strlen($value) > self::MAX_VALUE) {
                throw ValidationException::withMessages([$errorKey => __('Label values can be up to :max characters.', ['max' => self::MAX_VALUE])]);
            }
            $clean[$key] = $value;
        }
        if (count($clean) > self::MAX_LABELS) {
            throw ValidationException::withMessages([$errorKey => __('A machine can have up to :max labels.', ['max' => self::MAX_LABELS])]);
        }
        ksort($clean, SORT_NATURAL | SORT_FLAG_CASE);

        return $clean;
    }

    /**
     * Set what's given, leave the rest. A provider or region set by hand
     * stays, whatever the daemon detects; set to null, detection fills it
     * in again. Labels in `labels` are set (an empty value removes one),
     * and `replaceLabels` swaps the whole set.
     *
     * @param  array{provider?: string|null, region?: string|null, tier?: string|null, labels?: array<string, string|null>, replaceLabels?: bool}  $changes
     * @return list<string> the fields that changed
     */
    public static function apply(Machine $machine, array $changes): array
    {
        $before = ['provider' => $machine->provider, 'region' => $machine->region, 'tier' => $machine->tier, 'labels' => $machine->labels ?? []];

        if (array_key_exists('provider', $changes)) {
            $provider = trim((string) $changes['provider']);
            $machine->forceFill($provider === ''
                ? ['provider_manual' => false, 'provider' => DaemonFacts::provider($machine->facts ?? []) ?? 'Other']
                : ['provider_manual' => true, 'provider' => mb_substr($provider, 0, 60)]);
        }
        if (array_key_exists('region', $changes)) {
            $region = trim((string) $changes['region']);
            $machine->forceFill($region === ''
                ? ['region_manual' => false, 'region' => self::detectedRegion($machine)]
                : ['region_manual' => true, 'region' => mb_substr($region, 0, 60)]);
        }
        if (array_key_exists('tier', $changes)) {
            $tier = trim((string) $changes['tier']);
            $machine->forceFill(['tier' => $tier === '' ? null : mb_substr($tier, 0, 60)]);
        }
        if (array_key_exists('labels', $changes)) {
            $given = $changes['labels'];
            $merged = ($changes['replaceLabels'] ?? false) ? [] : ($machine->labels ?? []);
            foreach ($given as $key => $value) {
                $merged[trim((string) $key)] = $value;
            }
            $labels = self::labels($merged);
            $machine->forceFill(['labels' => $labels === [] ? null : $labels]);
        }

        $after = ['provider' => $machine->provider, 'region' => $machine->region, 'tier' => $machine->tier, 'labels' => $machine->labels ?? []];

        return array_keys(array_filter($after, fn ($value, $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The region the daemon reports (from the cloud's metadata), if any.
     *
     * @param  array<string, mixed>|null  $facts
     */
    public static function detectedRegion(Machine $machine, ?array $facts = null): ?string
    {
        $region = ($facts ?? $machine->facts ?? [])['hosting']['region'] ?? null;

        return is_string($region) && $region !== '' ? mb_substr($region, 0, 60) : null;
    }

    /**
     * What detection says from a fresh facts report, for the fields nobody
     * set by hand.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, string|null>
     */
    public static function fromFacts(Machine $machine, array $facts, ?string $provider): array
    {
        $changes = [];
        if (! $machine->provider_manual) {
            $changes['provider'] = $provider ?? $machine->provider;
        }
        if (! $machine->region_manual && ($region = self::detectedRegion($machine, $facts)) !== null) {
            $changes['region'] = $region;
        }

        return $changes;
    }

    /**
     * Every field the workspace's machines use, with the values in use:
     * for filters, groupings, policy rules and suggestions.
     *
     * @return array{providers: list<string>, regions: list<string>, tiers: list<string>, labels: array<string, list<string>>}
     */
    public static function inUse(Workspace $workspace): array
    {
        $machines = $workspace->machines()->get(['provider', 'region', 'tier', 'labels']);
        $labels = [];
        foreach ($machines as $machine) {
            foreach ($machine->labels ?? [] as $key => $value) {
                $labels[$key][$value] = true;
            }
        }
        ksort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        $distinct = fn (string $field) => array_values($machines->pluck($field)->filter(fn ($v) => is_string($v) && $v !== '')->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->all());

        return [
            'providers' => $distinct('provider'),
            'regions' => $distinct('region'),
            'tiers' => $distinct('tier'),
            'labels' => array_map(fn (array $values) => array_values(collect(array_keys($values))->sort(SORT_NATURAL | SORT_FLAG_CASE)->all()), $labels),
        ];
    }
}
