<?php

namespace App\Providers;

use App\Socialite\TikTokProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerSocialitePlatforms();
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
