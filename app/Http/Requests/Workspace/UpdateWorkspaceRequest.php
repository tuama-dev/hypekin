<?php

namespace App\Http\Requests\Workspace;

use App\Http\Requests\WorkspaceAbilityRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateWorkspaceRequest extends WorkspaceAbilityRequest
{
    protected string $ability = 'update';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
