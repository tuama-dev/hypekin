<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

test('an unverified user is redirected to the email verification notice', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user can access the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspace.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Dashboard'));
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
