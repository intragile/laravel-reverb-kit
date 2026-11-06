<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Console\Kernel;
use IntrAgile\ReverbKit\Publishing\GuardedPusherBroadcaster;
use IntrAgile\ReverbKit\Tests\TestCase;
use InvalidArgumentException;

final class WiringTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $extraConfig = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('broadcasting.connections.reverb.key', 'app-key');
        $app['config']->set('broadcasting.connections.reverb.secret', 'app-secret');
        $app['config']->set('broadcasting.connections.reverb.app_id', '100001');

        foreach ($this->extraConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    public function test_commands_registered(): void
    {
        $commands = app(Kernel::class)->all();

        foreach (['reverb:doctor', 'reverb:proxy', 'reverb:stack', 'reverb:env'] as $name) {
            $this->assertArrayHasKey($name, $commands);
        }
    }

    public function test_does_not_override_reverb_commands(): void
    {
        if (! class_exists('Laravel\Reverb\ReverbServiceProvider')) {
            $this->markTestSkipped('laravel/reverb is not installed.');
        }

        $commands = app(Kernel::class)->all();

        foreach (['reverb:start', 'reverb:restart', 'reverb:install'] as $name) {
            $this->assertArrayHasKey($name, $commands);
            $this->assertStringStartsWith('Laravel\\Reverb\\', $commands[$name]::class);
        }
    }

    public function test_guard_off_by_default(): void
    {
        $this->requirePusher();

        $driver = app(BroadcastManager::class)->connection('reverb');

        $this->assertInstanceOf(PusherBroadcaster::class, $driver);
        $this->assertNotInstanceOf(GuardedPusherBroadcaster::class, $driver);
    }

    public function test_guard_wraps_reverb_driver_when_enabled(): void
    {
        $this->requirePusher();
        $this->extraConfig = ['reverb-kit.publish.guard' => true];
        $this->refreshApplication();

        $this->assertInstanceOf(
            GuardedPusherBroadcaster::class,
            app(BroadcastManager::class)->connection('reverb'),
        );
    }

    public function test_invalid_oversize_mode_fails_when_guard_is_first_needed(): void
    {
        $this->requirePusher();
        $this->extraConfig = ['reverb-kit.publish.guard' => true, 'reverb-kit.publish.on_oversize' => 'ignore'];
        $this->refreshApplication();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reverb-kit.publish.on_oversize');

        app(BroadcastManager::class);
    }

    private function requirePusher(): void
    {
        if (! class_exists('Pusher\Pusher')) {
            $this->markTestSkipped('pusher/pusher-php-server is not installed.');
        }
    }
}
