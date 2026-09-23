<?php

use App\Enums\WorkspaceRole;
use App\Listeners\SendEmailVerificationNotification;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

test('users can register with email and password and are logged in', function () {
    $response = $this->post(route('register.store'), [
        'fullname' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'john@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', [
        'fullname' => 'John Doe',
        'email' => 'john@example.com',
    ]);
});

test('a new user gets a personal workspace when registering', function () {
    $this->post(route('register.store'), [
        'fullname' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'john@example.com')->firstOrFail();

    $workspace = $user->workspaces()->firstOrFail();

    expect($workspace->only(['name', 'slug']))
        ->toBe([
            'name' => "John Doe's Workspace",
            'slug' => 'john-does-workspace',
        ]);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner->value,
    ]);

    expect($user->workspaces()->count())->toBe(1);
});

test('a verification email is queued when a user registers', function () {
    Queue::fake();

    $this->post(route('register.store'), [
        'fullname' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'john@example.com')->firstOrFail();

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($user) {
        return $job->class === SendEmailVerificationNotification::class
            && $job->data[0] instanceof Registered
            && $job->data[0]->user->is($user);
    });
});

test('users cannot register with a duplicate email', function () {
    User::factory()->create(['email' => 'john@example.com']);

    $response = $this->from(route('register'))->post(route('register.store'), [
        'fullname' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('users cannot register with a short password', function () {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'fullname' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});
