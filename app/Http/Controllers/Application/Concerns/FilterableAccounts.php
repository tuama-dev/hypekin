<?php

namespace App\Http\Controllers\Application\Concerns;

use App\Models\SocialAccount;
use App\Models\Workspace;

trait FilterableAccounts
{
    /**
     * Every account in the workspace, shaped for the account filter bar
     * shared by the posts and calendar pages (platform, connection status and
     * avatar included so the pills can show their platform border and badge).
     *
     * @return array<int, array{id: string, platform: array{value: string, label: string}, display_name: string, status: array{value: string, label: string}, avatar_url: string|null}>
     */
    private function filterableAccounts(Workspace $workspace): array
    {
        return $workspace->socialAccounts()
            ->orderBy('display_name')
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->getKey(),
                'platform' => [
                    'value' => $account->platform->value,
                    'label' => $account->platform->label(),
                ],
                'display_name' => $account->display_name,
                'status' => [
                    'value' => $account->status->value,
                    'label' => $account->status->label(),
                ],
                'avatar_url' => $account->avatar_url,
            ])
            ->values()
            ->all();
    }
}
