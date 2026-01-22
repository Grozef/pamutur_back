<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ValueBetService;
use App\Services\CombinationService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ValueBetService::class);
        $this->app->singleton(CombinationService::class);
        $this->app->singleton(ValueBetService::class);       
        $this->app->singleton(CombinationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
