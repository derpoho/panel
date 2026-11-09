<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Support\RemoteImage;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use App\Support\Deploys\ServiceLabels;
use Illuminate\Support\Facades\Validator;

/**
 * Give a service an icon from an image on the web, stored like one
 * uploaded on its page. Nothing changes on the machine.
 */
final class SetServiceIcon extends Tool
{
    public function __construct(private readonly RemoteImage $images) {}

    public function name(): string
    {
        return 'set_service_icon';
    }

    public function title(): string
    {
        return 'Set a service’s icon';
    }

    public function description(): string
    {
        return 'Sets the icon shown next to a service in ServerOS, from a public https:// image URL: PNG, JPEG, WebP or GIF, up to 2 MB and 4000×4000 pixels (not SVG). ServerOS downloads it once and keeps its own copy, replacing any icon the service had. Use the image file’s own address, not a page showing it or one that redirects. Returns the new icon’s URL.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'url' => Schema::string('The image’s public https:// address.', maxLength: 2048),
        ], ['machine', 'service', 'url']);
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
            ...ServiceWork::serviceArguments(),
            'url' => ['required', 'string', 'max:2048', 'url:https'],
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);

        $file = $this->images->fetch($data['url']);
        try {
            // The same checks as an upload on the service page.
            $validator = Validator::make(['icon' => $file], ['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);
            if ($validator->fails()) {
                throw new ToolError('That image can’t be used: '.implode(' ', $validator->errors()->all()));
            }
            ServiceLabels::setIcon($machine, $service, $file);
        } finally {
            @unlink($file->getPathname());
        }

        return [
            'service' => ['key' => $service['key'], 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'icon' => ServiceLabels::iconUrl($machine, $service),
        ];
    }
}
