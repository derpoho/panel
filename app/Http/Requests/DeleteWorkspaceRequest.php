<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->workspace());
    }

    /**
     * The user types the workspace's address to confirm.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([$this->workspace()->slug])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => 'Type '.$this->workspace()->slug.' to confirm.',
            'confirmation.in' => 'That doesn’t match. Type '.$this->workspace()->slug.' to confirm.',
        ];
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
