<?php

namespace App\Support\Extensions;

use App\Models\Workspace;

/**
 * Where a workspace gets more (a bigger plan, more machines, extra AI tool
 * calls): the one link the panel's "upgrade", "choose a plan" and plan
 * limit prompts point at. A package supplies it; with none, those prompts
 * show no link.
 */
final readonly class UpgradeLink
{
    /**
     * @param  string  $label  In English; it's translated when shared.
     * @param  string  $route  A named route in the workspace group, like "workspace.billing.show".
     */
    public function __construct(
        public string $label,
        public string $route,
    ) {}

    /** On the workspace's host, like "/billing": for links in the dashboard. */
    public function path(Workspace $workspace): string
    {
        return route($this->route, ['workspace' => $workspace->slug], false);
    }

    /** In full, on the workspace's subdomain: for links that leave the page. */
    public function url(Workspace $workspace): string
    {
        return route($this->route, ['workspace' => $workspace->slug]);
    }

    /**
     * @return array{href: string, label: string}
     */
    public function toArray(Workspace $workspace): array
    {
        return [
            'href' => $this->path($workspace),
            'label' => __($this->label),
        ];
    }
}
