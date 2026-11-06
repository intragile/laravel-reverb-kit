<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the package into the application.
 *
 * laravel/reverb and laravel/doctor are optional: they are only referenced after their classes
 * have been found, so the application boots unchanged when either is missing.
 */
final class ReverbKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reverb-kit.php', 'reverb-kit');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/reverb-kit.php' => config_path('reverb-kit.php'),
            ], 'reverb-kit-config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/reverb-kit'),
            ], 'reverb-kit-stubs');
        }
    }
}
