<?php

namespace App\Support\Mcp;

use App\Enums\WorkspacePermission;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\McpOAuthClient;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's own audience: one workspace's MCP server, on the workspace's
 * domain. A request names it by that URL (its resource) or names none, and
 * then the person picks one of their workspaces on the consent page.
 */
final class WorkspaceAudience extends OAuthAudience
{
    public function key(): string
    {
        return McpToken::WORKSPACE;
    }

    public function scopes(): array
    {
        return Scopes::ALL;
    }

    public function describe(): array
    {
        return Scopes::describe();
    }

    public function normalise(string|array|null $requested): array
    {
        return Scopes::normalise($requested);
    }

    /** No resource (they choose a workspace), or exactly one workspace's MCP URL. */
    public function claims(mixed $resource): bool
    {
        return $resource === null || (is_string($resource) && OAuth::workspaceFor($resource) !== null);
    }

    public function consent(Request $request, User $user, mixed $resource): OAuthConsent|Response
    {
        $workspaces = $user->workspaces()->orderBy('name')->get();
        if ($resource !== null) {
            $workspace = OAuth::workspaceFor(is_string($resource) ? $resource : null);
            if ($workspace === null || ! $workspaces->contains('id', $workspace->id)) {
                return OAuthPage::problem("You’re signed in as {$user->email}, who isn’t a member of the workspace this app asked for. Sign in as someone who is, or ask to be invited.");
            }
            $workspaces = $workspaces->where('id', $workspace->id)->values();
        }
        if ($workspaces->isEmpty()) {
            return OAuthPage::problem('You’re not in any workspace yet. Create one first, then connect again.');
        }

        $requested = Scopes::normalise((string) $request->query('scope', implode(' ', Scopes::ALL)));

        return new OAuthConsent(
            pending: [
                'scopes' => $requested,
                'workspace' => $resource !== null ? $workspaces->first()->id : null,
            ],
            props: [
                'workspaces' => $workspaces->map(fn (Workspace $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'host' => $w->host(),
                    'role' => $w->roleOf($user)?->label(),
                    'canChange' => $w->allows($user, WorkspacePermission::ManageMachines),
                ])->values(),
                'fixedWorkspace' => $resource !== null,
                'requested' => $requested,
                'scopes' => Scopes::describe(),
                'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
                'account' => $user->email,
            ],
        );
    }

    public function approve(User $user, array $pending, array $choices): OAuthGrant|Response
    {
        $workspace = $user->workspaces()->whereKey($pending['workspace'] ?? $choices['workspace'] ?? 0)->first();
        if ($workspace === null) {
            return back()->withErrors(['workspace' => 'Choose a workspace you’re a member of.']);
        }
        if (! $workspace->satisfiesTwoFactor($user)) {
            return back()->withErrors(['workspace' => "{$workspace->name} requires two-factor sign-in; set it up first."]);
        }

        $scopes = [Scopes::READ];
        if (($choices['write'] ?? false) && in_array(Scopes::WRITE, $pending['scopes'], true)) {
            $scopes[] = Scopes::WRITE;
            if (($choices['commands'] ?? false) && in_array(Scopes::COMMANDS, $pending['scopes'], true)) {
                if (! $user->hasEnabledTwoFactorAuthentication()) {
                    return back()->withErrors(['commands' => 'Running commands needs two-factor authentication on your account.']);
                }
                $scopes[] = Scopes::COMMANDS;
            }
        }

        return new OAuthGrant($scopes, OAuth::resource($workspace), $workspace);
    }

    /** "Sam connected Claude through MCP", in the workspace's activity. */
    public function approved(User $user, McpOAuthClient $client, OAuthGrant $grant): void
    {
        if ($grant->workspace !== null) {
            Activity::record($grant->workspace, 'mcp.connect', $client->name, user: $user, meta: ['scopes' => $grant->scopes]);
        }
    }
}
