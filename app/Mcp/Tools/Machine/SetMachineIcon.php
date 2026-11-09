<?php

namespace App\Mcp\Tools\Machine;

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
 * Give a machine an icon from an image on the web, stored like one
 * uploaded on its settings page.
 */
final class SetMachineIcon extends Tool
{
    public function __construct(private readonly RemoteImage $images) {}

    public function name(): string
    {
        return 'set_machine_icon';
    }

    public function title(): string
    {
        return 'Set a machine’s icon';
    }

    public function description(): string
    {
        return 'Sets the icon shown next to a machine in the panel, from a public https:// image URL (PNG, JPEG, WebP or GIF, up to 2 MB and 4000×4000 pixels; not SVG). ServerOS downloads it once and keeps its own copy, replacing any icon the machine had. Use the image file’s own address (not a page that shows it, and not one that redirects). Returns the new icon’s URL.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'url' => Schema::string('The image’s public https:// address.', maxLength: 2048),
        ], ['machine', 'url']);
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
            'machine' => ['required', ServerWork::reference()],
            'url' => ['required', 'string', 'max:2048', 'url:https'],
        ]);
        $machine = $context->machine($data['machine']);
        ServerWork::throttle($context, 'icon', 20);

        $file = $this->images->fetch($data['url']);
        try {
            // The same checks as an upload on the settings page.
            $validator = Validator::make(['icon' => $file], ['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);
            if ($validator->fails()) {
                throw new ToolError('That image can’t be used: '.implode(' ', $validator->errors()->all()));
            }

            $previous = $machine->icon_path;
            $machine->forceFill(['icon_path' => $file->store('machine-icons', 'public')])->save();
            if ($previous !== null) {
                Storage::disk('public')->delete($previous);
            }
        } finally {
            @unlink($file->getPathname());
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'icon' => $machine->iconUrl(),
        ];
    }
}
