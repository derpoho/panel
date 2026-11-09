<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\Unsubscribes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The unsubscribe link in our emails. Opening it asks first, so a mail
 * scanner following links can't unsubscribe anyone; a POST (the button, or
 * a mail client's one-click unsubscribe) does it.
 *
 * A link can stop everything that isn't about the account, or (with
 * `list`) one workspace's alert emails or weekly summary for that person,
 * the same switches as their settings.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request): Response
    {
        [$email, $list, $workspace] = $this->target($request);

        return $this->page($email, $list, $workspace, $this->done($email, $list, $workspace), $request->fullUrl());
    }

    public function store(Request $request): Response
    {
        [$email, $list, $workspace] = $this->target($request);

        if ($list === null) {
            Unsubscribes::add($email);
        } elseif ($list === 'feedback') {
            User::query()->whereRaw('lower(email) = ?', [$email])->update(['feedback_emails' => false]);
        } elseif ($list === 'trial') {
            // The workspace's trial emails, which only ever go to its owner.
            if ($workspace !== null && $this->member($email, $workspace) !== null) {
                $workspace->forceFill(['trial_emails_off_at' => $workspace->trial_emails_off_at ?? now()])->saveQuietly();
            }
        } elseif ($workspace !== null && ($user = $this->member($email, $workspace)) !== null) {
            $workspace->members()->updateExistingPivot($user->id, $list === 'alerts' ? ['alert_emails' => 'none'] : ['weekly_summary' => false]);
        }

        return $this->page($email, $list, $workspace, true, null);
    }

    /**
     * The page for a link whose address isn't known: nothing more will come.
     */
    public static function finished(): Response
    {
        return response()->view('unsubscribe', ['email' => null, 'done' => true, 'action' => null, 'copy' => self::copy(null, null)]);
    }

    /**
     * @return array{0: string, 1: string|null, 2: Workspace|null}
     */
    private function target(Request $request): array
    {
        $email = $request->query('email');
        abort_unless(is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL), 404);

        $list = $request->query('list');
        abort_unless($list === null || in_array($list, Unsubscribes::LISTS, true), 404);

        $workspace = $list === null || $list === 'feedback' ? null : Workspace::query()->find((int) $request->query('workspace'));
        abort_if($list !== null && $list !== 'feedback' && $workspace === null, 404);

        return [Unsubscribes::normalise($email), $list, $workspace];
    }

    private function member(string $email, Workspace $workspace): ?User
    {
        return $workspace->members()->withPivot(['alert_emails', 'weekly_summary'])->whereRaw('lower(users.email) = ?', [$email])->first();
    }

    private function done(string $email, ?string $list, ?Workspace $workspace): bool
    {
        if ($list === 'feedback') {
            return ! User::query()->whereRaw('lower(email) = ?', [$email])->where('feedback_emails', true)->exists();
        }
        if ($list === null || $workspace === null) {
            return Unsubscribes::has($email);
        }

        $member = $this->member($email, $workspace);
        if ($member === null) {
            // Not in the workspace any more: nothing will come.
            return true;
        }
        if ($list === 'trial') {
            return $workspace->trial_emails_off_at !== null;
        }

        /** @var object{alert_emails: string|null, weekly_summary: bool|int|null} $pivot */
        $pivot = $member->getRelation('pivot');

        return $list === 'alerts' ? $pivot->alert_emails === 'none' : ! (bool) $pivot->weekly_summary;
    }

    private function page(string $email, ?string $list, ?Workspace $workspace, bool $done, ?string $action): Response
    {
        return response()->view('unsubscribe', [
            'email' => $email,
            'done' => $done,
            'action' => $done ? null : $action,
            'copy' => self::copy($list, $workspace, $email),
        ]);
    }

    /**
     * @return array{ask: string, askBody: string, doneTitle: string, doneBody: string, settings: string|null}
     */
    private static function copy(?string $list, ?Workspace $workspace, ?string $email = null): array
    {
        $name = $workspace?->name;

        return match ($list) {
            'alerts' => [
                'ask' => "Stop alert emails from {$name}?",
                'askBody' => 'You won’t be emailed when something breaks in this workspace. Its other owners and admins still will be, and the problems still show in ServerOS.',
                'doneTitle' => 'Alert emails are off',
                'doneBody' => "You won’t get alert emails from {$name}. You can turn them back on, or pick only some kinds, in its settings.",
                'settings' => $workspace ? 'https://'.$workspace->host().'/settings' : null,
            ],
            'summary' => [
                'ask' => "Stop the weekly summary for {$name}?",
                'askBody' => 'You won’t get the Monday email about this workspace any more.',
                'doneTitle' => 'Weekly summary is off',
                'doneBody' => "You won’t get the Monday email about {$name}. You can turn it back on in your settings.",
                'settings' => $workspace ? 'https://'.$workspace->host().'/settings/profile' : null,
            ],
            'trial' => [
                'ask' => "Stop trial emails for {$name}?",
                'askBody' => 'You won’t get the reminders before this workspace’s trial ends, or the note when it has. The panel still shows how long is left.',
                'doneTitle' => 'Trial emails are off',
                'doneBody' => "You won’t get any more emails about {$name}’s trial. You can pick a plan any time from Billing.",
                'settings' => null,
            ],
            'feedback' => [
                'ask' => 'Stop emails about feedback ideas?',
                'askBody' => 'You won’t be emailed when an idea you posted or voted for on the feedback board is planned, in progress or shipped.',
                'doneTitle' => 'Feedback emails are off',
                'doneBody' => 'You won’t get emails about ideas on the feedback board any more. The roadmap still shows where each one is.',
                'settings' => null,
            ],
            default => [
                'ask' => 'Unsubscribe from ServerOS emails?',
                'askBody' => 'We’ll stop emailing '.($email ?? 'you').', apart from emails about your account, like resetting a password.',
                'doneTitle' => 'You’re unsubscribed',
                'doneBody' => 'We won’t email '.($email ?? 'you').' again, apart from emails about your account.',
                'settings' => null,
            ],
        };
    }
}
