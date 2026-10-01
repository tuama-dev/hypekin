<?php

namespace App\Http\Requests\Post;

use App\Enums\Platform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiCaptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'brief' => ['required', 'string', 'max:500'],
            'tone' => ['nullable', Rule::in(['casual', 'professional', 'engaging', 'funny', 'informative'])],
            'platforms' => [
                'nullable',
                'array',
            ],
            'platforms.*' => [
                Rule::in(collect(Platform::publishable())->map->value->all()),
            ],
        ];
    }

    /**
     * The platforms the caption should include hashtags for, defaulting to
     * every publishable platform.
     *
     * @return list<string>
     */
    public function platforms(): array
    {
        return $this->filled('platforms')
            ? array_values(array_unique($this->input('platforms')))
            : collect(Platform::publishable())->map->value->values()->all();
    }
}
