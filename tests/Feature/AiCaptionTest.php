<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Ai\Agents\CaptionGenerator;
use App\Models\Post;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
});

function aiCaptionUser(): array
{
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    return [$user, $workspace];
}

test('the ai caption endpoint returns the generated caption, title and hashtags', function () {
    CaptionGenerator::fake([
        [
            'caption' => 'Our hand-finished walnut tables, made to last a lifetime.',
            'title' => 'Tables that last',
            'hashtags' => [
                'facebook' => ['#woodworking', '#handmade'],
                'instagram' => ['#woodworking', '#handmade', '#homedecor'],
            ],
        ],
    ]);

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A post about hand-finished walnut tables.',
            'tone' => 'informative',
            'platforms' => ['facebook', 'instagram'],
        ])
        ->assertOk()
        ->assertJson([
            'caption' => 'Our hand-finished walnut tables, made to last a lifetime.',
            'title' => 'Tables that last',
            'hashtags' => [
                'facebook' => ['#woodworking', '#handmade'],
                'instagram' => ['#woodworking', '#handmade', '#homedecor'],
            ],
        ]);
});

test('the ai caption prompt includes the brief', function () {
    CaptionGenerator::fake([
        [
            'caption' => 'Draft caption.',
            'title' => null,
            'hashtags' => ['facebook' => ['#tag']],
        ],
    ]);

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'Announcing our next live workshop.',
            'platforms' => ['facebook'],
        ])
        ->assertOk();

    CaptionGenerator::assertPrompted(
        fn (AgentPrompt $prompt) => $prompt->contains('Announcing our next live workshop.'),
    );
});

test('the ai caption endpoint platforms default to every publishable platform', function () {
    CaptionGenerator::fake([
        [
            'caption' => 'Draft caption.',
            'title' => null,
            'hashtags' => [
                'facebook' => ['#tag'],
                'instagram' => ['#tag'],
                'linkedin' => ['#tag'],
                'tiktok' => ['#tag'],
            ],
        ],
    ]);

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A general update.',
        ])
        ->assertOk()
        ->assertJsonPath('hashtags.facebook', ['#tag'])
        ->assertJsonPath('hashtags.instagram', ['#tag'])
        ->assertJsonPath('hashtags.linkedin', ['#tag'])
        ->assertJsonPath('hashtags.tiktok', ['#tag']);
});

test('the ai caption endpoint validates the brief and tone', function () {
    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('brief');

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => str_repeat('a', 501),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('brief');

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A valid brief.',
            'tone' => 'aggressive',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tone');
});

test('the ai caption endpoint rejects invalid platforms', function () {
    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A valid brief.',
            'platforms' => ['snapchat'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platforms.0');
});

test('the ai caption endpoint is rate limited', function () {
    CaptionGenerator::fake([
        [
            'caption' => 'Draft caption.',
            'title' => null,
            'hashtags' => ['facebook' => ['#tag']],
        ],
    ]);

    [$user, $workspace] = aiCaptionUser();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)
            ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
                'brief' => 'A valid brief.',
            ])
            ->assertOk();
    }

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A valid brief.',
        ])
        ->assertStatus(429);
});

test('the ai caption endpoint returns 422 when the provider is unconfigured', function () {
    config()->set('ai.providers.openai.key', null);

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A valid brief.',
        ])
        ->assertStatus(422)
        ->assertJson(['error' => "AI captioning isn't configured."]);
});

test('the ai caption endpoint persists nothing', function () {
    CaptionGenerator::fake([
        [
            'caption' => 'Draft caption.',
            'title' => null,
            'hashtags' => ['facebook' => ['#tag']],
        ],
    ]);

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A valid brief.',
        ])
        ->assertOk();

    expect(Post::count())->toBe(0);
});

test('a non-member cannot use the ai caption endpoint', function () {
    $member = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($member);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson(route('workspace.posts.ai-caption', ['workspace' => $workspace]), [
            'brief' => 'A valid brief.',
        ])
        ->assertNotFound();
});

test('the create composer exposes ai_enabled when the provider is configured', function () {
    config()->set('ai.providers.openai.key', 'test-key');

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Create')
            ->where('ai_enabled', true));
});

test('the create composer hides the ai feature when unconfigured', function () {
    config()->set('ai.providers.openai.key', null);

    [$user, $workspace] = aiCaptionUser();

    $this->actingAs($user)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Create')
            ->where('ai_enabled', false));
});
