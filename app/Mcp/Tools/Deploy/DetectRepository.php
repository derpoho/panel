<?php

namespace App\Mcp\Tools\Deploy;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Validation\ValidationException;

/**
 * The deploy dialog's "Custom" tab: paste a GitHub link, and ServerOS
 * looks at its latest commit to say what it is and which template builds
 * it (ServiceDetectController, RepositoryDetector).
 */
final class DetectRepository extends Tool
{
    public function __construct(
        private readonly RepositoryDetector $detector,
        private readonly ServiceTemplateCatalog $catalog,
    ) {}

    public function name(): string
    {
        return 'detect_repository';
    }

    public function title(): string
    {
        return 'Detect what a repository is';
    }

    public function description(): string
    {
        return 'Looks at a GitHub repository (public, or private and shared with the workspace’s GitHub connection), as the dashboard’s deploy dialog does when a link is pasted: reads the files at the root of the latest commit on its default branch (or the branch given) and the manifests that name a framework, and says what it is (a Laravel web app, an Express API, a Vite static site…) and which app template builds it. '
            .'Returns detected, template (its key, or null when nothing matched), framework, type, the reasons, the repository’s clone address, the branch and the exact commit it looked at, a suggested name to deploy it as, and the template’s summary. '
            .'Then deploy it with deploy_template (template, repo, ref = the commit returned, so what was checked is what’s deployed, plus php and health_path when it returns them: an older PHP app needs its PHP version). When nothing matched, pick an app template yourself (list_templates kind=app), or "custom-dockerfile" if the repository has its own Dockerfile. Only GitHub repositories can be detected (private ones once the workspace has connected GitHub in Settings → General); others can still be deployed with deploy_from_git or an app template.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'repository' => Schema::string('A GitHub repository: a link like https://github.com/you/app (a /tree/<branch> link works too) or owner/repo.', maxLength: 300),
            'branch' => Schema::string('A branch or tag to look at instead of the default branch.', maxLength: 255),
        ], ['repository']);
    }

    public function permission(): WorkspacePermission
    {
        // As the dialog's detection: only for people who can deploy.
        return WorkspacePermission::ManageMachines;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'repository' => ['required', 'string', 'max:300'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $repository = trim($data['repository']);
        if (filled($data['branch'] ?? null)) {
            $branch = trim((string) $data['branch']);
            if (! RefResolver::validRef($branch)) {
                throw new ToolError('branch must be a branch or tag name, like main or v1.2.0.');
            }
            if (str_contains($repository, '/tree/')) {
                throw new ToolError('The link already names a branch (/tree/…): leave out branch, or give the plain repository link.');
            }
            $repository = preg_replace('#(\.git)?/?$#', '', $repository);
            $repository = (str_contains($repository, 'github.com') ? $repository : "https://github.com/{$repository}")."/tree/{$branch}";
        }

        try {
            $found = $this->detector->detect($repository, $context->workspace);
        } catch (ValidationException $e) {
            throw new ToolError(Deploys::explain($e));
        }

        $template = $found['template'] !== null ? $this->catalog->find($found['template']) : null;

        return [
            ...$found,
            'suggestedName' => self::serviceName($found['repo']) ?: 'app',
            'templateInfo' => $template === null ? null : [
                ...ListTemplates::row($template),
                'healthPath' => $template->health,
            ],
            'appTemplates' => $template === null
                ? array_values(array_map(fn (ServiceTemplate $t) => $t->slug, array_filter($this->catalog->all(), fn (ServiceTemplate $t) => $t->isApp())))
                : null,
            'next' => $template !== null
                ? "Deploy it with deploy_template: template={$template->slug}, repo={$found['repo']}, ref={$found['commit']}. Ask the person which machine, the name and the domain if they haven’t said; list_templates with template={$template->slug} shows its settings."
                : 'No template matched. If it has its own Dockerfile somewhere, use template=custom-dockerfile; otherwise choose one of appTemplates with the person.',
        ];
    }

    /** As the dialog names it: "https://github.com/acme/My_App.git" → "my-app". */
    public static function serviceName(string $repo): string
    {
        $parts = explode('/', preg_replace('/\.git$/', '', $repo));
        $name = strtolower((string) end($parts));
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = preg_replace('/^[^a-z]+|-+$/', '', (string) $name);

        return substr((string) $name, 0, 63);
    }
}
