<?php

namespace App\Actions\Workspaces;

use App\Events\WorkspaceCreated;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Edition;
use App\Support\Extensions\TrialCards;
use App\Support\WorkspaceSlugs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CreateWorkspace
{
    public function __construct(private WorkspaceSlugs $slugs) {}

    /**
     * Create a workspace owned by the user, generating a slug from the
     * workspace's name (or the owner's) when none is given. Only the first
     * one someone owns gets the Pro trial: the rest start without a plan.
     */
    public function create(User $owner, ?string $slug = null, ?string $name = null): Workspace
    {
        $slug = filled($slug) ? $slug : $this->slugs->generate($name ?? $owner->name, $owner->email);
        $trial = self::getsTrial($owner);

        $workspace = new Workspace([
            'name' => $name ?? Str::headline($slug),
            'slug' => $slug,
            'trial_ends_at' => $trial ? now()->addDays(Workspace::TRIAL_DAYS) : null,
        ]);
        // Someone's first workspace, made by them as they sign up, starts
        // with the welcome flow. Later ones, and ones staff make for
        // people, go straight to the dashboard. A self-hosted install has
        // no trial, so there it's the first one they own.
        $actor = Auth::user();
        $selfMade = $actor === null || $actor->getAuthIdentifier() === $owner->id;
        $first = Edition::selfHosted() ? Workspace::query()->where('owner_id', $owner->id)->doesntExist() : $trial;
        $workspace->onboarded_at = $first && $selfMade ? null : now();
        // A new signup's own trial workspace may be asked for a card first
        // (TrialCards).
        if ($trial && $selfMade) {
            $workspace->signup_variant = app(TrialCards::class)->assign($owner);
        }
        $workspace->owner()->associate($owner);
        $workspace->save();

        if ($trial) {
            $owner->forceFill(['trial_used_at' => now()])->saveQuietly();
        }

        $workspace->members()->attach($owner, ['role' => 'owner']);
        // The first line of its history: theirs when they made it (signing
        // up, or from the switcher), ServerOS's when staff made it for them
        // from the admin pages.
        if (request()->user() === null || AuditContext::isRequestUser($owner)) {
            Activity::record($workspace, 'workspace.create', $workspace->name, user: $owner);
        } else {
            Activity::system($workspace, 'workspace.create', $workspace->name);
        }

        // A package can act on it before it's handed back.
        event(new WorkspaceCreated($workspace, $owner));

        return $workspace->refresh();
    }

    /**
     * Whether the next workspace this person creates comes with the trial.
     * Never on a self-hosted install, where every workspace is active
     * without a plan.
     */
    public static function getsTrial(User $owner): bool
    {
        return $owner->trial_used_at === null && ! Edition::selfHosted();
    }
}
