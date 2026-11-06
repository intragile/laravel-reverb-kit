<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use IntrAgile\ReverbKit\Console\DoctorCommand;
use IntrAgile\ReverbKit\Console\EnvCommand;
use IntrAgile\ReverbKit\Console\ProxyCommand;
use IntrAgile\ReverbKit\Console\StackCommand;
use IntrAgile\ReverbKit\Doctor\DoctorRunner;
use IntrAgile\ReverbKit\Doctor\LaravelDoctor\ReverbKitDiagnostic;
use IntrAgile\ReverbKit\Doctor\RuleSet;
use IntrAgile\ReverbKit\Publishing\GuardedPusherBroadcaster;
use IntrAgile\ReverbKit\Publishing\PayloadGuard;
use InvalidArgumentException;
use RuntimeException;

/**
 * Wires the package into the application.
 *
 * laravel/reverb, laravel/doctor and the Pusher client are optional: they are only referenced
 * after their classes have been found, so the application boots unchanged when any is missing.
 */
final class ReverbKitServiceProvider extends ServiceProvider
{
    private const DOCTOR_CLASS = 'Laravel\Doctor\Doctor';

    private const DOCTOR_DIAGNOSTIC_CLASS = 'Laravel\Doctor\Diagnostic';

    private const BROADCAST_MANAGER_CLASS = 'Illuminate\Broadcasting\BroadcastManager';

    private const PUSHER_CLASS = 'Pusher\Pusher';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reverb-kit.php', 'reverb-kit');

        $this->app->bind(DoctorRunner::class, static fn (): DoctorRunner => new DoctorRunner(RuleSet::make()));
    }

    public function boot(): void
    {
        if (class_exists(self::DOCTOR_CLASS) && class_exists(self::DOCTOR_DIAGNOSTIC_CLASS)) {
            // Fires immediately when laravel/doctor was already resolved, otherwise on first use.
            $this->callAfterResolving(self::DOCTOR_CLASS, static function (object $doctor): void {
                ReverbKitDiagnostic::register($doctor);
            });
        }

        if ($this->config()->get('reverb-kit.publish.guard') === true) {
            $this->registerPayloadGuard();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class, ProxyCommand::class, StackCommand::class, EnvCommand::class]);

            $this->publishes([
                __DIR__.'/../config/reverb-kit.php' => config_path('reverb-kit.php'),
            ], 'reverb-kit-config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/reverb-kit'),
            ], 'reverb-kit-stubs');
        }
    }

    /**
     * Wraps the reverb driver as soon as the broadcast manager is resolved, before any
     * connection has been created from it. Configuration errors surface at that point, when the
     * guard is first needed, instead of breaking every request during boot.
     */
    private function registerPayloadGuard(): void
    {
        $this->callAfterResolving(self::BROADCAST_MANAGER_CLASS, function (object $manager): void {
            $mode = $this->config()->get('reverb-kit.publish.on_oversize');

            if (! in_array($mode, [GuardedPusherBroadcaster::MODE_REPORT, GuardedPusherBroadcaster::MODE_THROW], true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid reverb-kit.publish.on_oversize value "%s"; expected "%s" or "%s".',
                    is_scalar($mode) ? (string) $mode : get_debug_type($mode),
                    GuardedPusherBroadcaster::MODE_REPORT,
                    GuardedPusherBroadcaster::MODE_THROW,
                ));
            }

            if (! class_exists(self::PUSHER_CLASS)) {
                throw new RuntimeException('The reverb-kit payload guard needs pusher/pusher-php-server, which the reverb broadcast driver uses.');
            }

            if ($manager instanceof BroadcastManager) {
                GuardedPusherBroadcaster::extend($manager, PayloadGuard::fromConfig($this->config()), $mode);
            }
        });
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
