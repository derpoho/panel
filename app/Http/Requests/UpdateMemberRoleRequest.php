<?php

namespace App\Http\Requests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->workspace());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $fixed = array_map(fn (WorkspaceRole $role) => $role->value, WorkspaceRole::assignable());
                if (! in_array($value, $fixed, true) && ! preg_match('/^custom:\d+$/', (string) $value)) {
                    $fail('Pick a role.');
                }
            }],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $workspace = $this->workspace();

                if (str_starts_with($this->string('role')->toString(), 'custom:')) {
                    if (! $workspace->hasCustomRoles()) {
                        $validator->errors()->add('role', 'Custom roles are on Business.');

                        return;
                    }
                    if ($this->chosenCustomRole() === null) {
                        $validator->errors()->add('role', 'That role no longer exists.');

                        return;
                    }
                }
                $member = $this->member();

                if ($workspace->roleOf($member) === WorkspaceRole::Owner) {
                    $validator->errors()->add('role', 'The owner’s role can’t be changed.');
                } elseif ($member->is($this->user())) {
                    $validator->errors()->add('role', 'You can’t change your own role.');
                } elseif ($this->chosenRole()->isStaffRole() && ! $workspace->hasStaffRoles()) {
                    $validator->errors()->add('role', 'Member and Viewer roles are on Pro and Business.');
                }
            },
        ];
    }

    /**
     * The base role. A custom role sits on top of Viewer, so a workspace
     * that leaves Business falls back to read-only access.
     */
    public function chosenRole(): WorkspaceRole
    {
        $value = $this->string('role')->toString();

        return str_starts_with($value, 'custom:') ? WorkspaceRole::Viewer : WorkspaceRole::from($value);
    }

    public function chosenCustomRole(): ?WorkspaceCustomRole
    {
        $value = $this->string('role')->toString();

        return str_starts_with($value, 'custom:')
            ? $this->workspace()->customRoles()->find((int) substr($value, 7))
            : null;
    }

    public function member(): User
    {
        /** @var User */
        return $this->route('member');
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
