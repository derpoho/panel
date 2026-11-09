<?php

namespace App\Mcp;

use App\Enums\WorkspacePermission;
use Illuminate\Support\Facades\Validator;

/**
 * One thing an agent can do in a workspace. A tool says what it needs
 * (the scope a connection must have and the permission the person's role
 * must give), describes its input as JSON Schema, and does its work in
 * handle(), returning data for the agent. Problems are ToolErrors.
 */
abstract class Tool
{
    /** snake_case, unique across all tools. */
    abstract public function name(): string;

    /** A short title for people, like "Restart a service". */
    abstract public function title(): string;

    /**
     * What it does and when to use it, written for the model: what it
     * returns, what the arguments mean, and anything to check with the
     * person first.
     */
    abstract public function description(): string;

    /**
     * @param  array<string, mixed>  $arguments  already checked against inputSchema() by validate() where the tool asks
     * @return array<string, mixed>|list<mixed>|string what the agent gets back
     */
    abstract public function handle(McpContext $context, array $arguments): array|string;

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return Schema::object();
    }

    /** Scopes::READ, WRITE or COMMANDS. */
    public function scope(): string
    {
        return Scopes::READ;
    }

    /** What the person's role must allow, beyond being a member. */
    public function permission(): ?WorkspacePermission
    {
        return $this->scope() === Scopes::READ ? null : WorkspacePermission::ManageMachines;
    }

    /** It deletes or stops things, or can't be undone. */
    public function destructive(): bool
    {
        return false;
    }

    /** Doing it twice is the same as doing it once. */
    public function idempotent(): bool
    {
        return $this->scope() === Scopes::READ;
    }

    /** It reaches outside ServerOS (a URL, a Git host). */
    public function openWorld(): bool
    {
        return false;
    }

    /**
     * The tool as tools/list describes it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => $this->scope() === Scopes::READ,
                'destructiveHint' => $this->destructive(),
                'idempotentHint' => $this->idempotent(),
                'openWorldHint' => $this->openWorld(),
            ],
        ];
    }

    /**
     * Check the arguments with Laravel's validator; the first problem with
     * each becomes the error the agent sees.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    protected function validate(array $arguments, array $rules, array $messages = []): array
    {
        $validator = Validator::make($arguments, $rules, $messages);
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }
}
