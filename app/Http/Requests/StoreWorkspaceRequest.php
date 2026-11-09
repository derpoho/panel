<?php

namespace App\Http\Requests;

use App\Models\Machine;
use App\Support\Machines\MovableMachines;
use App\Support\WorkspaceSlugs;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the slug the same way registration does.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('workspace'))) {
            $this->merge(['workspace' => trim(strtolower(trim($this->input('workspace'))), '-')]);
        }
    }

    /**
     * The picked machines, each from a workspace where they can manage
     * machines; anything else is refused rather than quietly skipped.
     *
     * @return Collection<int, Machine>
     */
    public function machinesToMove(): Collection
    {
        $ids = array_map('intval', (array) $this->validated('machines', []));
        if ($ids === []) {
            return collect();
        }

        return MovableMachines::for($this->user())->whereIn('id', $ids)->values();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'workspace' => ['nullable', ...app(WorkspaceSlugs::class)->rules()],
            // Machines to move in from workspaces they manage machines in.
            'machines' => ['nullable', 'array', 'max:100'],
            'machines.*' => ['integer', 'distinct'],
        ];
    }
}
