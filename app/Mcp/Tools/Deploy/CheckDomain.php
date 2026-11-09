<?php

namespace App\Mcp\Tools\Deploy;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Deploys\DeployRequest;
use App\Support\Domains\DomainCheck;

/**
 * The deploy dialog's DNS check (DomainCheckController): does a domain
 * point at the machine yet, and if not, which record to add.
 */
final class CheckDomain extends Tool
{
    public function __construct(private readonly DomainCheck $check) {}

    public function name(): string
    {
        return 'check_domain';
    }

    public function title(): string
    {
        return 'Check a domain points at a machine';
    }

    public function description(): string
    {
        return 'Looks up a domain’s DNS (A and AAAA records) and says whether it points at the machine, as the deploy dialog does while a domain is typed. status is "ready" (it points here: HTTPS is set up as it deploys), "missing" (no record yet), "elsewhere" (it points at other addresses, listed in found) or "proxied" (behind Cloudflare’s proxy, which blocks the certificate: set the record to DNS only, the grey cloud). '
            .'record is the DNS record to add or change where the person manages the domain. A deploy can go ahead before DNS is ready: HTTPS starts once the record points at the machine, which can take a few minutes. Only reads DNS; changes nothing.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'domain' => Schema::string('The domain, like app.example.com (a pasted URL works too).', maxLength: 300),
            'wildcard' => Schema::boolean('Also check that every subdomain points at the machine, for an app serving *.domain: a *.domain record is needed.'),
        ], ['machine', 'domain']);
    }

    public function permission(): WorkspacePermission
    {
        // As the dialog's check: only for people who can deploy.
        return WorkspacePermission::ManageMachines;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'domain' => ['required', 'string', 'max:300'],
            'wildcard' => ['sometimes', 'boolean'],
        ]);
        $machine = $context->machine($args['machine']);

        $data = $this->validate(['domain' => DeployRequest::bareDomain($args['domain'])], [
            'domain' => ['required', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
        ], ['domain.regex' => 'Enter a domain like app.example.com.']);

        $result = $this->check->check($machine, $data['domain']);
        $answer = [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...$result,
            'advice' => self::advice($result, $machine->name),
        ];

        if ($args['wildcard'] ?? false) {
            $wildcard = $this->check->checkWildcard($machine, $data['domain']);
            $answer['wildcard'] = [...$wildcard, 'advice' => self::advice($wildcard, $machine->name, 'Every subdomain')];
        }

        return $answer;
    }

    /**
     * @param  array{domain: string, status: string, found: list<string>, record: array{type: string, name: string, value: string}|null}  $result
     */
    private static function advice(array $result, string $machine, ?string $subject = null): string
    {
        $record = $result['record'];
        $subject ??= $result['domain'];

        return match ($result['status']) {
            'ready' => "{$subject} points to {$machine}. HTTPS is set up as it deploys.",
            'missing' => "No DNS record for {$result['domain']} yet.".($record ? " Add {$record['type']} {$record['name']} → {$record['value']} where the domain is managed." : ' The machine has no known IP address to point it at.'),
            'proxied' => 'It’s behind Cloudflare’s proxy, which blocks the certificate. Set the record to DNS only (grey cloud)'.($record ? ": {$record['type']} {$record['name']} → {$record['value']}." : '.'),
            default => 'It points to '.implode(', ', $result['found']).', not this machine.'.($record ? " Change the record to {$record['type']} {$record['name']} → {$record['value']}." : ''),
        };
    }
}
