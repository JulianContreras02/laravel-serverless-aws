<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

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
        // Catch lazy loading and silently dropped attributes in development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // migrate:fresh, db:wipe and friends refuse to run against production.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
