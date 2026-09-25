<?php

namespace App\Actions\Application\SocialAccount;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Throwable;

class ConnectInstagramAccountsAction
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Connect the Instagram business accounts linked to the pages the token can
     * manage, storing one connected SocialAccount per Instagram account.
     *
     * @return list<SocialAccount>
     */
    public function execute(
        Workspace $workspace,
        string $userAccessToken,
        User $connectedBy,
    ): array {
        return collect($this->fetchPages($userAccessToken))
            ->map(fn (array $page): ?SocialAccount => $this->storeLinkedAccount(
                $workspace,
                $page,
                $userAccessToken,
                $connectedBy,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Fetch the pages the token can manage via the Graph "me/accounts" edge.
     *
     * @return list<array{id: string, name: string, access_token: string}>
     */
    private function fetchPages(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout(10)
                ->timeout(30)
                ->get(self::GRAPH_BASE.'/me/accounts', [
                    'fields' => 'id,name,access_token,instagram_business_account{id,name}',
                ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed() || $response->json('error') !== null) {
            return [];
        }

        return $response->json('data', []);
    }

    private function storeLinkedAccount(
        Workspace $workspace,
        array $page,
        string $userAccessToken,
        User $connectedBy,
    ): ?SocialAccount {
        $instagramAccount = $page['instagram_business_account'] ?? null;
        $instagramId = $instagramAccount['id'] ?? null;
        $instagramName = $instagramAccount['name'] ?? null;

        if ($instagramId === null || $instagramName === null) {
            return null;
        }

        return $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => Platform::Instagram->value,
                'external_account_id' => $instagramId,
            ],
            [
                'display_name' => $instagramName,
                'access_token' => $userAccessToken,
                'refresh_token' => null,
                'token_expires_at' => null,
                'status' => SocialAccountStatus::Connected,
                'connected_by_user_id' => $connectedBy->getKey(),
                'connected_at' => now(),
            ],
        );
    }
}
