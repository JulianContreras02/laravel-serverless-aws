<?php

namespace App\Providers;

use App\Support\Metrics\Metrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Metrics::class, fn () => Metrics::toStderr(
            Config::boolean('shortlinks.metrics.enabled'),
            Config::string('shortlinks.metrics.namespace'),
            Config::string('shortlinks.metrics.environment'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Catch lazy loading and silently dropped attributes in development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // migrate:fresh, db:wipe and friends refuse to run against production.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
