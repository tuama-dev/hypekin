<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A connected social account on the user's own workspace.
 *
 * Shared by the post and retry test files, so it lives here rather than in one
 * of them: a helper defined in a single test file is only in scope when that
 * file is loaded, which silently makes the other file unrunnable on its own.
 */
function linkedAccount(User $user, array $attributes = []): SocialAccount
{
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    return SocialAccount::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ], $attributes));
}
