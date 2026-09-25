<?php

namespace App\Actions\Application\SocialAccount;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Throwable;

class ConnectFacebookPagesAction
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Connect the Facebook pages the authenticated user manages, storing one
     * connected SocialAccount per page.
     *
     * @return list<SocialAccount>
     */
    public function execute(
        Workspace $workspace,
        string $userAccessToken,
        User $connectedBy,
    ): array {
        $pages = $this->fetchPages($userAccessToken);

        return collect($pages)
            ->map(fn (array $page): ?SocialAccount => $this->storePage(
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
                    'fields' => 'id,name,access_token',
                ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed() || $response->json('error') !== null) {
            return [];
        }

        return $response->json('data', []);
    }

    private function storePage(
        Workspace $workspace,
        array $page,
        string $userAccessToken,
        User $connectedBy,
    ): ?SocialAccount {
        $pageId = $page['id'] ?? null;
        $pageName = $page['name'] ?? null;
        $pageToken = $page['access_token'] ?? null;

        if ($pageId === null || $pageName === null || $pageToken === null) {
            return null;
        }

        return $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => Platform::Facebook->value,
                'external_account_id' => $pageId,
            ],
            [
                'display_name' => $pageName,
                'access_token' => $pageToken,
                'refresh_token' => null,
                'token_expires_at' => null,
                'status' => SocialAccountStatus::Connected,
                'connected_by_user_id' => $connectedBy->getKey(),
                'connected_at' => now(),
            ],
        );
    }
}
