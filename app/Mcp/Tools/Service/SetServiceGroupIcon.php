<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Support\RemoteImage;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Give a service group an icon from an image on the web, stored like one
 * uploaded in the group's dialog.
 */
final class SetServiceGroupIcon extends Tool
{
    public function __construct(private readonly RemoteImage $images) {}

    public function name(): string
    {
        return 'set_service_group_icon';
    }

    public function title(): string
    {
        return 'Set a service group’s icon';
    }

    public function description(): string
    {
        return 'Sets the icon of a service group on a machine, from a public https:// image URL: PNG, JPEG, WebP or GIF, up to 2 MB and 4000×4000 pixels (not SVG). ServerOS downloads it once and keeps its own copy, replacing any icon the group had. Use the image file’s own address, not a page showing it or one that redirects. Returns the new icon’s URL.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'group' => Schema::string('The group: its id or name (as list_service_groups shows).', maxLength: 120),
            'url' => Schema::string('The image’s public https:// address.', maxLength: 2048),
        ], ['machine', 'group', 'url']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function openWorld(): bool
    {
        return true;
    }

    #[NotAudited('Cosmetic')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServiceWork::reference()],
            'group' => ['required', ServiceWork::reference()],
            'url' => ['required', 'string', 'max:2048', 'url:https'],
        ]);
        $machine = $context->machine($data['machine']);
        $group = ServiceGroups::find($machine, $data['group']);

        $file = $this->images->fetch($data['url']);
        try {
            // The same checks as an upload in the group's dialog.
            $validator = Validator::make(['icon' => $file], ['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);
            if ($validator->fails()) {
                throw new ToolError('That image can’t be used: '.implode(' ', $validator->errors()->all()));
            }

            $previous = $group->icon_path;
            $group->forceFill(['icon_path' => $file->store("service-icons/{$machine->id}", 'public')])->save();
            if ($previous !== null) {
                Storage::disk('public')->delete($previous);
            }
        } finally {
            @unlink($file->getPathname());
        }

        return [
            'group' => ['id' => $group->id, 'name' => $group->name, 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'icon' => Storage::disk('public')->url($group->icon_path),
        ];
    }
}
