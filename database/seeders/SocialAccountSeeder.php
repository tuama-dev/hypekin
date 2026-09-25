<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SocialAccountSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::query()
            ->where('email', 'benkinnnn@gmail.com')
            ->firstOrFail();

        $workspace = $user->workspaces()->firstOrFail();

        $demoAccounts = [
            'linkedin' => [
                'external_account_id' => 'linkedin-demo-company-page',
                'display_name' => 'Demo Company Page',
                'access_token' => 'demo-linkedin-access-token',
                'refresh_token' => 'demo-linkedin-refresh-token',
            ],
            'facebook' => [
                'external_account_id' => 'facebook-demo-page',
                'display_name' => "Benkin's Facebook Page",
                'access_token' => 'demo-facebook-access-token',
                'refresh_token' => null,
            ],
            'instagram' => [
                'external_account_id' => 'instagram-demo-business',
                'display_name' => 'demo.creators',
                'access_token' => 'demo-instagram-access-token',
                'refresh_token' => null,
            ],
            'tiktok' => [
                'external_account_id' => 'tiktok-demo-creator',
                'display_name' => '@demo.creators',
                'access_token' => 'demo-tiktok-access-token',
                'refresh_token' => 'demo-tiktok-refresh-token',
            ],
        ];

        foreach ($demoAccounts as $platform => $account) {
            SocialAccount::updateOrCreate(
                [
                    'workspace_id' => $workspace->getKey(),
                    'platform' => Platform::from($platform),
                    'external_account_id' => $account['external_account_id'],
                ],
                [
                    'display_name' => $account['display_name'],
                    'access_token' => $account['access_token'],
                    'refresh_token' => $account['refresh_token'],
                    'token_expires_at' => now()->addDays(60),
                    'status' => SocialAccountStatus::Connected,
                    'connected_by_user_id' => $user->getKey(),
                    'connected_at' => now(),
                ],
            );
        }
    }
}
