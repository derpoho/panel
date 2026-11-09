<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Deleting your account: your password if you have one, otherwise your
 * email typed out (Google, GitHub and SSO sign-ups have no password). Not
 * while you own a workspace other people are in: it would go with you.
 */
class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return $user->password_set_at !== null
            ? ['password' => $this->currentPasswordRules()]
            : ['confirmation' => ['required', 'string', Rule::in([$user->email])]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => 'Type your email to confirm.',
            'confirmation.in' => 'That doesn’t match your email.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var User $user */
            $user = $this->user();
            $shared = self::sharedWorkspaces($user);
            if ($shared !== []) {
                $validator->errors()->add('account', 'You own '.implode(', ', $shared).', which other people are in. Delete it, or ask us to hand it over, before you delete your account.');
            }
        }];
    }

    /**
     * Workspaces this person owns that someone else is a member of.
     *
     * @return list<string>
     */
    public static function sharedWorkspaces(User $user): array
    {
        $names = [];
        foreach ($user->ownedWorkspaces()->whereHas('members', fn ($q) => $q->whereKeyNot($user->id))->orderBy('name')->get() as $workspace) {
            $names[] = $workspace->name;
        }

        return $names;
    }
}
