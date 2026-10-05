<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use App\Settings\Settings;
use App\Socialite\TikTokProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not shared: one Settings instance per HTTP request and per
        // queue job, discarded between them. The retry and TikTok poll policy is
        // read from inside publish jobs, so a cache shared across processes could
        // leave a worker publishing under a policy an operator had already
        // changed. See App\Settings\Settings.
        $this->app->scoped(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerPolicies();
        $this->registerSocialitePlatforms();
        $this->registerRateLimiters();
    }

    /**
     * Bind the models that carry authorization rules to their policy.
     *
     * Laravel would discover these by naming convention; the explicit map keeps
     * the authorization surface greppable and breaks loudly on a rename.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(Workspace::class, WorkspacePolicy::class);
    }

    /**
     * Register the Socialite drivers used for linking social accounts.
     */
    protected function registerSocialitePlatforms(): void
    {
        Socialite::extend('facebook-posting', fn (mixed $app): FacebookProvider => Socialite::buildProvider(
            FacebookProvider::class,
            $app['config']['services.facebook-posting'],
        ));

        Socialite::extend('instagram', fn (mixed $app): FacebookProvider => Socialite::buildProvider(
            FacebookProvider::class,
            $app['config']['services.instagram'],
        ));

        Socialite::extend('tiktok-login', fn (mixed $app): TikTokProvider => Socialite::buildProvider(
            TikTokProvider::class,
            $app['config']['services.tiktok-login'],
        ));

        Socialite::extend('tiktok', fn (mixed $app): TikTokProvider => Socialite::buildProvider(
            TikTokProvider::class,
            $app['config']['services.tiktok'],
        ));
    }

    /**
     * Register the named rate limiters used by the application's routes.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('ai-caption', function (Request $request): Limit {
            $workspace = $request->route('workspace');

            return Limit::perMinute(6)->by(
                $request->user()?->getKey().'|'.($workspace instanceof Workspace ? $workspace->getKey() : (string) $workspace),
            );
        });

        // Retries are already capped per post by the audit log and spaced by the
        // cooldown, so this only exists to brake a client that hammers the
        // endpoint. It is keyed per post, not per user, so retrying a second post
        // is never blocked by a burst against the first. The legitimate cadence
        // is one retry per post per cooldown, so the allowance is generous.
        RateLimiter::for('post-retry', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                $request->user()?->getKey().'|'.$request->route('post'),
            );
        });

        /**
         * Minting an upload policy is cheap for the server but hands out a
         * writable destination on the bucket, so an unmetered endpoint turns
         * into a way to fill someone else's storage. Keyed per user and
         * workspace because that is the unit the uploads belong to.
         */
        RateLimiter::for('media-upload', function (Request $request): Limit {
            $workspace = $request->route('workspace');

            return Limit::perMinute(30)->by(
                $request->user()?->getKey().'|'.($workspace instanceof Workspace ? $workspace->getKey() : (string) $workspace),
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
