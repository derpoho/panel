<?php

namespace App\Mcp\Tools\Service;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Work out what a public GitHub repository is, and which template deploys
 * it, as the deploy dialog does when a link is pasted.
 */
final class DetectServices extends Tool
{
    /** As the dashboard's route allows: 20 a minute. */
    private const PER_MINUTE = 20;

    public function __construct(
        private readonly RepositoryDetector $detector,
        private readonly ServiceTemplateCatalog $templates,
    ) {}

    public function name(): string
    {
        return 'detect_services';
    }

    public function title(): string
    {
        return 'Detect what a repository is';
    }

    public function description(): string
    {
        return 'Looks at a public GitHub repository (a link like https://github.com/owner/app, a /tree/<branch> link, or owner/app) and says what it is and which ServerOS template deploys it, like pasting a link in the deploy dialog: detected, template (its slug, with its name, description and port), framework (Laravel, Next.js, Django…), type (Web app, API, Static site or App), the repo URL, branch and commit looked at (the latest on the default branch, or the branch in the link), and reasons. '
            .'It only reads the repository on GitHub; nothing is deployed. Private repositories aren’t supported. When nothing is detected, a template can still be picked by hand.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'repository' => Schema::string('The GitHub repository: a link like https://github.com/owner/app (optionally /tree/<branch>), or owner/app.', maxLength: 300),
        ], ['repository']);
    }

    public function permission(): WorkspacePermission
    {
        // The deploy dialog it belongs to is for people who manage machines.
        return WorkspacePermission::ManageMachines;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['repository' => ['required', 'string', 'max:300']]);

        $key = 'mcp-detect:'.$context->user->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw new ToolError('Too many repositories looked at in the last minute. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }
        RateLimiter::hit($key, 60);

        try {
            $found = $this->detector->detect($data['repository'], $context->workspace);
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', Arr::flatten($e->errors())));
        }
        $template = $found['template'] === null ? null : $this->templates->find($found['template']);

        return [
            ...$found,
            'template' => $template === null ? $found['template'] : [
                'slug' => $template->slug,
                'name' => $template->name,
                'description' => $template->description,
                'port' => $template->port,
                'web' => $template->web,
            ],
        ];
    }
}
