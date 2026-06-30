<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\MirrorInstance;
use App\Observers\MirrorInstanceObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sostituisce il comando migrate con uno che rispetta MIGRATIONS_ENABLED (Laravel 12: Migrator + Dispatcher)
        $this->app->singleton(
            \Illuminate\Database\Console\Migrations\MigrateCommand::class,
            function ($app) {
                return new \App\Console\Commands\MigrateCommand(
                    $app['migrator'],
                    $app[\Illuminate\Contracts\Events\Dispatcher::class]
                );
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\URL::forceScheme('https');
    
        MirrorInstance::observe(MirrorInstanceObserver::class);
    }
}
