<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\WorkspaceCustomRole;
use App\Models\WorkspaceInvitation;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if ($invitation !== null && $invitation->workspace->hasMember($request->user())) {
            $invitation->delete();

            return Inertia::location($invitation->workspace->url());
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'status' => $this->statusOf($invitation, $request),
            'invitation' => $invitation === null ? null : [
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'workspace' => $invitation->workspace->name,
                'host' => $invitation->workspace->host(),
                'invitedBy' => $invitation->inviterName(),
            ],
        ])->toResponse($request);
    }

    #[Audited]
    public function accept(Request $request, string $token): Response
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        abort_unless($this->statusOf($invitation, $request) === 'valid', 403);

        $workspace = $invitation->workspace;

        if (! $workspace->hasMember($request->user())) {
            $workspace->members()->attach($request->user(), [
                'role' => $invitation->role->value,
                'custom_role_id' => $invitation->custom_role_id,
            ]);
            $custom = $invitation->custom_role_id === null ? null : WorkspaceCustomRole::query()->find($invitation->custom_role_id);
            Activity::record($workspace, 'member.join', meta: ['role' => $custom->name ?? $invitation->role->label()]);
        } else {
            AuditContext::nothingChanged();
        }

        $invitation->delete();

        return Inertia::location($workspace->url());
    }

    /**
     * @return 'valid'|'invalid'|'expired'|'wrong-account'
     */
    private function statusOf(?WorkspaceInvitation $invitation, Request $request): string
    {
        return match (true) {
            $invitation === null => 'invalid',
            $invitation->isExpired() => 'expired',
            ! $invitation->isFor($request->user()) => 'wrong-account',
            default => 'valid',
        };
    }
}
