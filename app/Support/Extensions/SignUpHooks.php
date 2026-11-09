<?php

namespace App\Support\Extensions;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * What a package does when someone signs up, with the sign-up form or with
 * Google or GitHub, like noting where they came from.
 * Registered with Extensions::addSignUpHooks; override only what's needed.
 * Every one is asked, in the order added.
 */
abstract class SignUpHooks
{
    /**
     * The account was just made, in the same transaction, before its first
     * workspace is.
     */
    public function signedUp(Request $request, User $user): void
    {
        //
    }

    /** Where the account came from, in a few words, or null to say nothing. */
    public function source(User $user): ?string
    {
        return null;
    }
}
