<?php

namespace App\Support;

use App\Jobs\SendNtfy;
use Illuminate\Support\Facades\Route;

/**
 * Pushes to the team's phones through ntfy when something happens worth
 * hearing about: a signup, a payment, a plan change, a machine connecting,
 * a job application. Everyone subscribed to NTFY_TOPIC in the ntfy app
 * gets them. Pushes pass through ntfy, so they never carry anything about
 * a person: no names, emails, workspace or host names, and links go to
 * admin lists, not to someone's page. With no topic set, nothing is sent.
 */
class TeamPush
{
    /** @param  string  $tag  An ntfy tag; emoji names show as an icon. */
    public static function send(string $title, string $message, ?string $url = null, string $tag = 'bell'): void
    {
        if (blank(config('services.ntfy.topic'))) {
            return;
        }

        // After the surrounding transaction commits, so a rolled-back
        // signup or webhook never announces itself.
        SendNtfy::dispatch($title, $message, $url, $tag)->afterCommit();
    }

    /**
     * A staff page to link a push to, by route name. Most staff pages come
     * from a package, so an install without it (a self-hosted one) links
     * nowhere rather than failing the signup or connection the push is
     * about.
     */
    public static function admin(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
    }
}
