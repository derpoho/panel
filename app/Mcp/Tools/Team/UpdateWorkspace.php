<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Support\RemoteImage;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Rename the workspace or change its logo, as the General settings tab
 * does. Its address can't be changed there, so not here either.
 */
final class UpdateWorkspace extends Tool
{
    public function __construct(private readonly RemoteImage $images) {}

    public function name(): string
    {
        return 'update_workspace';
    }

    public function title(): string
    {
        return 'Rename the workspace or change its logo';
    }

    public function description(): string
    {
        return 'Changes how the workspace appears to everyone in it, as Settings › General does: its `name` (up to 255 characters), and its logo, either from `logo_url` (a public https:// image: PNG, JPEG, WebP or GIF, up to 2 MB and 4000×4000 pixels, not SVG; ServerOS downloads it once and keeps its own copy, replacing the old logo) or removed with `remove_logo`. '
            .'Give at least one. The workspace’s address (its subdomain) can’t be changed. Everyone in the workspace sees the change, so check the new name or logo with the person first. Returns the workspace as it is now.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('A new name for the workspace.', maxLength: 255),
            'logo_url' => Schema::string('A public https:// address of an image to use as the logo.', maxLength: 2048),
            'remove_logo' => Schema::boolean('true to remove the logo (the workspace’s initial is shown instead).'),
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageSettings;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        if (array_key_exists('slug', $arguments) || array_key_exists('address', $arguments)) {
            throw new ToolError('The workspace’s address can’t be changed. Nothing was changed.');
        }
        // The dashboard trims what's typed; blank is the same as missing.
        if (is_string($arguments['name'] ?? null)) {
            $arguments['name'] = trim($arguments['name']);
        }
        $data = $this->validate($arguments, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:2048', 'url:https', 'prohibited_if_accepted:remove_logo'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'The name can’t be empty.',
            'logo_url.prohibited_if_accepted' => 'Give a logo_url or remove_logo, not both.',
        ]);
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        $logoUrl = $data['logo_url'] ?? null;
        if (! isset($data['name']) && $logoUrl === null && ! $removeLogo) {
            throw new ToolError('Say what to change: a new name, a logo_url, or remove_logo.');
        }

        $workspace = $context->workspace;
        $changed = [];

        // Fetch and check the logo first, so a bad image changes nothing.
        $file = $logoUrl === null ? null : $this->logo($logoUrl);

        try {
            if (isset($data['name']) && $data['name'] !== $workspace->name) {
                $before = $workspace->name;
                $workspace->update(['name' => $data['name']]);
                $context->record('workspace.rename', $workspace->name, meta: ['from' => $before]);
                $changed[] = 'name';
            }

            if ($file !== null) {
                $previous = $workspace->logo_path;
                $workspace->forceFill(['logo_path' => $file->store('workspace-logos', 'public')])->save();
                if ($previous !== null) {
                    Storage::disk('public')->delete($previous);
                }
                $context->record('workspace.logo');
                $changed[] = 'logo';
            } elseif ($removeLogo && $workspace->logo_path !== null) {
                Storage::disk('public')->delete($workspace->logo_path);
                $workspace->forceFill(['logo_path' => null])->save();
                $context->record('workspace.logo_remove');
                $changed[] = 'logo';
            }
        } finally {
            if ($file !== null) {
                @unlink($file->getPathname());
            }
        }

        if ($changed === []) {
            AuditContext::nothingChanged();
        }

        return [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'host' => $workspace->host(),
                'logoUrl' => $workspace->logoUrl(),
                'url' => $context->url('/'),
            ],
            'changed' => $changed,
            'note' => $changed === [] ? 'Nothing needed changing.' : 'Workspace updated.',
            'settingsUrl' => $context->url('/settings/general'),
        ];
    }

    /** The image, downloaded and checked as an upload on the General tab is. */
    private function logo(string $url): UploadedFile
    {
        $file = $this->images->fetch($url);

        $validator = Validator::make(['logo' => $file], [
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ]);
        if ($validator->fails()) {
            @unlink($file->getPathname());

            throw new ToolError('That image can’t be used: '.implode(' ', $validator->errors()->all()));
        }

        return $file;
    }
}
