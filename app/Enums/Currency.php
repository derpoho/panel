<?php

namespace App\Enums;

use App\Http\Middleware\TrustCloudflare;
use App\Models\Workspace;
use App\Support\Extensions\Billing;
use Illuminate\Http\Request;

/**
 * The currencies plans are priced in. GBP is the base price; USD and EUR
 * are fixed prices of their own, so checkout charges what the site shows.
 * Everyone else sees and pays GBP, converted at checkout.
 */
enum Currency: string
{
    case GBP = 'GBP';
    case USD = 'USD';
    case EUR = 'EUR';

    /** Countries that pay in euros. */
    private const array EURO_COUNTRIES = [
        'AT', 'BE', 'HR', 'CY', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PT', 'SK', 'SI', 'ES',
        // Use the euro without being in the eurozone.
        'AD', 'MC', 'SM', 'VA', 'ME', 'XK',
    ];

    /** Countries and territories that pay in US dollars. */
    private const array DOLLAR_COUNTRIES = [
        'US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM', 'EC', 'SV', 'PA', 'TL', 'FM', 'MH', 'PW',
    ];

    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
            self::USD => '$',
            self::EUR => '€',
        };
    }

    public static function forCountry(?string $country): self
    {
        $country = strtoupper((string) $country);

        return match (true) {
            in_array($country, self::DOLLAR_COUNTRIES, true) => self::USD,
            in_array($country, self::EURO_COUNTRIES, true) => self::EUR,
            default => self::GBP,
        };
    }

    /**
     * The currency to show someone browsing: ?currency= when they picked
     * one, otherwise the one where Cloudflare says they are.
     */
    public static function forVisitor(Request $request): self
    {
        $picked = self::tryFrom(strtoupper((string) $request->query('currency')));

        return $picked ?? self::forCountry($request->attributes->get(TrustCloudflare::COUNTRY));
    }

    /**
     * The currency a workspace is billed in. A subscription's currency
     * never changes, plan changes included, so it's that once there is one
     * (App\Support\Extensions\Billing), and otherwise what the person
     * looking would pay at checkout.
     */
    public static function forWorkspace(Workspace $workspace, Request $request): self
    {
        return app(Billing::class)->currency($workspace) ?? self::forVisitor($request);
    }
}
