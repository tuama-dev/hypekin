<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

test('an unverified user is bounced to the email verification notice', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user can access the dashboard', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Dashboard')
            ->where('auth.workspace.slug', $workspace->slug)
            ->where('auth.workspace.role', WorkspaceRole::Owner->value));
});

test('a user cannot access a workspace they are not a member of', function () {
    $workspaceOwner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($workspaceOwner);

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertNotFound();
});

test('a verification link can be resent but is blocked during the cooldown', function () {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('flash.error');

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

test('the resend cooldown expires after the configured time', function () {
    Notification::fake();

    Carbon::setTestNow('2026-01-01 10:00:00');

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)->post(route('verification.send'));

    Carbon::setTestNow('2026-01-01 10:01:00');

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    Carbon::setTestNow();

    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

test('the resend cooldown comes from the verification.resend_cooldown setting', function () {
    Notification::fake();

    app(Settings::class)->set('verification.resend_cooldown', 10);

    Carbon::setTestNow('2026-01-01 10:00:00');

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)->post(route('verification.send'));

    Carbon::setTestNow('2026-01-01 10:00:05');

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertSessionHas('flash.error');

    Carbon::setTestNow('2026-01-01 10:00:11');

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertSessionHas('flash.success');

    Carbon::setTestNow();

    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

test('resending for an already verified user redirects to a real workspace', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));
});
