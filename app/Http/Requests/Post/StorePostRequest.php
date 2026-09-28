<?php

namespace App\Http\Requests\Post;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
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
        $workspaceId = $this->route('workspace')->getKey();

        return [
            'caption' => ['required', 'string', 'max:3000'],
            'title' => ['nullable', 'string', 'max:22'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => [
                'required',
                'string',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'media_id' => [
                'nullable',
                'string',
                Rule::exists('media', 'id')->where('workspace_id', $workspaceId),
            ],
        ];
    }

    /**
     * Validate account publishability and cross-field media requirements after
     * the base rules pass.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $accounts = SocialAccount::whereKey($this->input('targets'))->get()->keyBy(fn (SocialAccount $account): string => (string) $account->getKey());

                $hasInvalidAccount = collect($this->input('targets'))
                    ->contains(fn (string $accountId): bool => ! $accounts->has($accountId)
                        || $accounts[$accountId]->status !== SocialAccountStatus::Connected
                        || ! in_array($accounts[$accountId]->platform, Platform::publishable(), true));

                if ($hasInvalidAccount) {
                    $validator->errors()->add('targets', 'One or more selected accounts can not be published to.');

                    return;
                }

                $targetsRequiringMedia = collect($this->input('targets'))
                    ->contains(fn (string $accountId): bool => in_array($accounts[$accountId]->platform, [Platform::Instagram, Platform::Tiktok], true));

                if ($targetsRequiringMedia && $this->input('media_id') === null) {
                    $validator->errors()->add('media_id', 'Instagram and TikTok posts require an image.');
                }

                $targetsTiktok = collect($this->input('targets'))
                    ->contains(fn (string $accountId): bool => $accounts[$accountId]->platform === Platform::Tiktok);

                if ($targetsTiktok && ! $this->filled('title')) {
                    $validator->errors()->add('title', 'TikTok posts require a title.');
                }
            },
        ];
    }
}
