<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PostTargetFailedNotification;
use Inertia\Testing\AssertableInertia;

function dashboardTarget(User $user, Workspace $workspace, Platform $platform, string $postId): PostTarget
{
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'external_account_id' => 'acct-'.$platform->value,
        'status' => SocialAccountStatus::Connected,
    ]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);

    return PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
        'platform_post_id' => $postId,
    ]);
}

function snapshot(PostTarget $target, string $date, array $data): PostMetric
{
    return PostMetric::factory()->create([
        'post_target_id' => $target->id,
        'post_id' => $target->post_id,
        'platform' => $target->socialAccount->platform,
        'snapshot_date' => $date,
        'data' => $data,
    ]);
}

test('the dashboard analytics are safe zeros for an empty workspace', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Dashboard')
            ->where('analytics.kpis.reach', 0)
            ->where('analytics.kpis.impressions', 0)
            ->where('analytics.kpis.engagements', 0)
            ->where('analytics.kpis.posts_published_30d', 0)
            ->where('analytics.kpis.accounts_connected', 0)
            ->where('analytics.kpis.unread_notifications', 0)
            ->where('analytics.platform_mix', [])
            ->where('analytics.needs_attention.count', 0)
            ->where('analytics.needs_attention.items', [])
            ->where('analytics.upcoming', [])
            ->where('analytics.best_post', null)
            ->where('analytics.onboarding.has_accounts', false)
            ->where('analytics.onboarding.has_media', false)
            ->where('analytics.onboarding.has_posts', false)
            ->has('analytics.trend', 30));
});

test('the dashboard KPIs aggregate the latest snapshots across platforms', function () {
    $this->travelTo('2026-01-15 12:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $facebook = dashboardTarget($user, $workspace, Platform::Facebook, 'fb-post-1');
    snapshot($facebook, now()->toDateString(), [
        'likes' => 10,
        'comments' => 2,
        'shares' => 1,
        'saves' => null,
        'impressions' => 500,
        'reach' => 300,
        'engagements' => null,
        'views' => null,
    ]);

    $tiktok = dashboardTarget($user, $workspace, Platform::Tiktok, 'tt-post-1');
    snapshot($tiktok, now()->toDateString(), [
        'likes' => 3,
        'comments' => 0,
        'shares' => 0,
        'saves' => null,
        'impressions' => null,
        'reach' => null,
        'engagements' => null,
        'views' => 200,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.kpis.reach', 500)
            ->where('analytics.kpis.impressions', 700)
            ->where('analytics.kpis.engagements', 16)
            ->where('analytics.kpis.posts_published_30d', 2)
            ->where('analytics.platform_mix.0.platform', 'facebook')
            ->where('analytics.platform_mix.0.reach', 300)
            ->where('analytics.platform_mix.1.platform', 'tiktok')
            ->where('analytics.platform_mix.1.reach', 200));
});

test('the dashboard KPIs only count the latest snapshot per target', function () {
    $this->travelTo('2026-01-15 12:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $target = dashboardTarget($user, $workspace, Platform::Facebook, 'fb-post-1');

    snapshot($target, now()->subDays(3)->toDateString(), [
        'likes' => 5,
        'comments' => 0,
        'shares' => 0,
        'saves' => null,
        'impressions' => 1000,
        'reach' => 100,
        'engagements' => null,
        'views' => null,
    ]);
    snapshot($target, now()->toDateString(), [
        'likes' => 9,
        'comments' => 1,
        'shares' => 0,
        'saves' => null,
        'impressions' => 2000,
        'reach' => 400,
        'engagements' => null,
        'views' => null,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.kpis.reach', 400)
            ->where('analytics.kpis.impressions', 2000)
            ->where('analytics.kpis.engagements', 10));
});

test('the dashboard analytics ignore snapshots older than the 30 day window', function () {
    $this->travelTo('2026-01-15 12:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $target = dashboardTarget($user, $workspace, Platform::Facebook, 'fb-post-1');

    snapshot($target, now()->subDays(31)->toDateString(), [
        'likes' => 1,
        'comments' => 1,
        'shares' => 1,
        'saves' => null,
        'impressions' => 999,
        'reach' => 999,
        'engagements' => null,
        'views' => null,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.kpis.reach', 0)
            ->where('analytics.kpis.impressions', 0)
            ->where('analytics.trend.0.date', now()->subDays(29)->toDateString())
            ->where('analytics.trend.29.date', now()->toDateString()));
});

test('the dashboard trend groups snapshot reach by day', function () {
    $this->travelTo('2026-01-15 12:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $first = dashboardTarget($user, $workspace, Platform::Facebook, 'fb-post-1');
    snapshot($first, now()->subDays(3)->toDateString(), [
        'likes' => 0,
        'comments' => 0,
        'shares' => 0,
        'saves' => null,
        'impressions' => 50,
        'reach' => 50,
        'engagements' => null,
        'views' => null,
    ]);
    snapshot($first, now()->toDateString(), [
        'likes' => 0,
        'comments' => 0,
        'shares' => 0,
        'saves' => null,
        'impressions' => 100,
        'reach' => 100,
        'engagements' => null,
        'views' => null,
    ]);

    $second = dashboardTarget($user, $workspace, Platform::Instagram, 'ig-post-1');
    snapshot($second, now()->subDay()->toDateString(), [
        'likes' => 1,
        'comments' => 0,
        'shares' => 0,
        'saves' => 1,
        'impressions' => 250,
        'reach' => 250,
        'engagements' => null,
        'views' => null,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.trend.26.reach', 50)
            ->where('analytics.trend.28.reach', 250)
            ->where('analytics.trend.29.reach', 100));
});

test('the dashboard pipeline snapshot counts posts by status', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Published]);
    Post::factory()->scheduled()->for($workspace)->create(['created_by_user_id' => $user->id]);
    Post::factory()->failed()->for($workspace)->create(['created_by_user_id' => $user->id]);
    Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Draft]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.kpis.posts_published_30d', 1)
            ->where('analytics.pipeline.0.status', 'published')
            ->where('analytics.pipeline.0.count', 1)
            ->where('analytics.pipeline.1.status', 'publishing')
            ->where('analytics.pipeline.1.count', 0)
            ->where('analytics.pipeline.2.status', 'scheduled')
            ->where('analytics.pipeline.2.count', 1)
            ->where('analytics.pipeline.3.status', 'failed')
            ->where('analytics.pipeline.3.count', 1)
            ->where('analytics.pipeline.4.status', 'draft')
            ->where('analytics.pipeline.4.count', 1)
            ->where('analytics.pipeline.5.status', 'canceled')
            ->where('analytics.pipeline.5.count', 0));
});

test('the dashboard surfaces failed targets needing attention', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook,
        'external_account_id' => 'acct-facebook',
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Failed]);
    $failed = PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Rejected post',
    ]);

    $otherWorkspace = app(CreateWorkspaceAction::class)->ensure(User::factory()->create());
    $otherAccount = SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'platform' => Platform::LinkedIn,
        'external_account_id' => 'acct-linkedin',
        'status' => SocialAccountStatus::Connected,
    ]);
    PostTarget::factory()->failed()->create([
        'post_id' => Post::factory()->for($otherWorkspace)->create(['created_by_user_id' => User::factory(), 'status' => PostStatus::Failed])->id,
        'social_account_id' => $otherAccount->id,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.needs_attention.count', 1)
            ->where('analytics.needs_attention.items.0.post_id', $post->id)
            ->where('analytics.needs_attention.items.0.caption', 'Rejected post')
            ->where('analytics.needs_attention.items.0.display_name', $account->display_name)
            ->where('analytics.needs_attention.items.0.error_message', 'Platform rejected the request.'));
});

test('the dashboard lists the next scheduled posts soonest first', function () {
    $this->travelTo('2026-01-15 12:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $soon = Post::factory()->scheduled(now()->addHour()->toDateTimeString())->for($workspace)->create(['created_by_user_id' => $user->id]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'external_account_id' => 'acct-instagram',
        'status' => SocialAccountStatus::Connected,
    ]);
    PostTarget::factory()->create([
        'post_id' => $soon->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Queued,
    ]);

    $later = Post::factory()->scheduled(now()->addDays(2)->toDateTimeString())->for($workspace)->create(['created_by_user_id' => $user->id]);

    Post::factory()->scheduled(now()->subDay()->toDateTimeString())->for($workspace)->create(['created_by_user_id' => $user->id]);
    Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Draft]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('analytics.upcoming', 2)
            ->where('analytics.upcoming.0.id', $soon->id)
            ->where('analytics.upcoming.0.platforms', ['instagram'])
            ->where('analytics.upcoming.1.id', $later->id));
});

test('the dashboard highlights the best performing post', function () {
    $this->travelTo('2026-01-15 12:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $facebook = dashboardTarget($user, $workspace, Platform::Facebook, 'fb-post-1');
    snapshot($facebook, now()->toDateString(), [
        'likes' => 0,
        'comments' => 0,
        'shares' => 0,
        'saves' => null,
        'impressions' => 800,
        'reach' => 800,
        'engagements' => null,
        'views' => null,
    ]);

    $instagram = dashboardTarget($user, $workspace, Platform::Instagram, 'ig-post-1');
    snapshot($instagram, now()->toDateString(), [
        'likes' => 0,
        'comments' => 0,
        'shares' => 0,
        'saves' => 0,
        'impressions' => 1200,
        'reach' => 1200,
        'engagements' => null,
        'views' => null,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.best_post.post_id', $instagram->post_id)
            ->where('analytics.best_post.platform', 'instagram')
            ->where('analytics.best_post.reach', 1200)
            ->where('analytics.best_post.caption', $instagram->caption));
});

test('the dashboard counts unread notifications for the user', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $target = dashboardTarget($user, $workspace, Platform::Facebook, 'fb-post-1');

    $user->notify(new PostTargetFailedNotification($target));

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.kpis.unread_notifications', 1)
            ->where('analytics.kpis.accounts_connected', 1));
});
