<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\CreateWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardRedirectController extends Controller
{
    /**
     * Send the user to their workspace's dashboard on its subdomain, creating a
     * workspace first for users who don't have one yet. A ?to=/path lands on
     * that page of it instead (links from the changelog use this).
     */
    public function __invoke(Request $request, CreateWorkspace $createWorkspace): Response
    {
        $user = $request->user();

        $workspace = $user->defaultWorkspace() ?? $createWorkspace->create($user);

        $to = $request->query('to');
        $path = is_string($to) && preg_match('#^/(?!/)[\w\-./?=&%]*$#', $to) ? $to : '';

        return Inertia::location(rtrim($workspace->url(), '/').$path);
    }
}
