<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google or GitHub account someone signs in with.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider google or github
 * @property string $provider_id the account's id at the provider
 * @property string|null $email
 */
#[Fillable(['provider', 'provider_id', 'email'])]
class SocialAccount extends Model
{
    /** The providers people can sign in with, when their keys are set. */
    public const array PROVIDERS = ['google', 'github'];

    /**
     * Providers with a client ID and secret in the env.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(
            self::PROVIDERS,
            fn (string $provider) => filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret")),
        ));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
