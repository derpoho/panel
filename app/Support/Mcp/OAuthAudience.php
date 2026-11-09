<?php

namespace App\Support\Mcp;

use App\Models\McpOAuthClient;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an MCP OAuth token can be for: its audience. The panel's own is one
 * workspace's MCP server (WorkspaceAudience); a package adds more from its
 * service provider with Extensions::addOAuthAudience. The OAuth server
 * (OAuthAuthorizeController, OAuthTokenController, the server metadata)
 * asks the audience a request is for everything that isn't plain OAuth:
 * whose consent page it is, which scopes it has, what an approval grants.
 *
 * See docs/extensions.md.
 */
abstract class OAuthAudience
{
    /**
     * What's stored with its codes and tokens (mcp_oauth_codes.audience and
     * mcp_tokens.audience), like workspace. Never change it once
     * tokens exist: they'd stop working.
     */
    abstract public function key(): string;

    /**
     * Every scope it has, in order: the server metadata lists them all
     * (scopes_supported).
     *
     * @return list<string>
     */
    abstract public function scopes(): array;

    /**
     * Each scope's label and description, for the consent page.
     *
     * @return array<string, array{label: string, description: string}>
     */
    abstract public function describe(): array;

    /**
     * The scopes asked for, made sensible: only its own, and whatever it
     * always grants. Used when a code becomes a token and when a refresh
     * asks for fewer.
     *
     * @param  string|list<string>|null  $requested
     * @return list<string>
     */
    abstract public function normalise(string|array|null $requested): array;

    /**
     * Whether an authorization request is for this audience, by its
     * resource parameter (null when it named none). Only one audience may
     * claim a resource; when none does, the app is told invalid_target.
     */
    abstract public function claims(mixed $resource): bool;

    /**
     * The consent page for a request it claims: what to keep until the
     * person decides and the props the page shows, or a response instead
     * (a problem page, OAuthPage::problem).
     */
    abstract public function consent(Request $request, User $user, mixed $resource): OAuthConsent|Response;

    /**
     * The person approved: what the code grants, or a response instead
     * (back with errors, or a problem page).
     *
     * @param  array<string, mixed>  $pending  what consent() asked to keep, with the request's client, redirect, challenge and state
     * @param  array<string, mixed>  $choices  what they chose on the page: workspace, write, commands
     */
    abstract public function approve(User $user, array $pending, array $choices): OAuthGrant|Response;

    /** Called once the code for an approval is made, like to note it in the workspace's activity. */
    public function approved(User $user, McpOAuthClient $client, OAuthGrant $grant): void {}
}
