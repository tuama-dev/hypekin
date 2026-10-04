<?php

namespace App\Http\Requests\Post;

use App\Http\Requests\WorkspaceAbilityRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePostScheduleRequest extends WorkspaceAbilityRequest
{
    protected string $ability = 'publish';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A null date publishes the post immediately.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
