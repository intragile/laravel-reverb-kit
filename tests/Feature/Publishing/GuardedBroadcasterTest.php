<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Publishing;

use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use IntrAgile\ReverbKit\Publishing\GuardedPusherBroadcaster;
use IntrAgile\ReverbKit\Publishing\PayloadGuard;
use IntrAgile\ReverbKit\Publishing\PayloadTooLarge;
use IntrAgile\ReverbKit\Tests\Fixtures\RecordingPusher;
use IntrAgile\ReverbKit\Tests\TestCase;
use Pusher\Pusher;

final class GuardedBroadcasterTest extends TestCase
{
    private RecordingPusher $pusher;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Pusher::class)) {
            $this->markTestSkipped('pusher/pusher-php-server is not installed.');
        }

        $this->pusher = new RecordingPusher('key', 'secret', '1');
    }

    public function test_report_mode_reports_and_does_not_send(): void
    {
        Exceptions::fake();

        $broadcaster = new GuardedPusherBroadcaster($this->pusher, new PayloadGuard(1100, 1024), 'report');
        $broadcaster->broadcast(['orders.1'], 'OrderShipped', ['text' => str_repeat('x', 200)]);

        Exceptions::assertReported(PayloadTooLarge::class);
        $this->assertSame([], $this->pusher->calls);
    }

    public function test_throw_mode_throws(): void
    {
        $broadcaster = new GuardedPusherBroadcaster($this->pusher, new PayloadGuard(1100, 1024), 'throw');

        try {
            $broadcaster->broadcast(['orders.1'], 'OrderShipped', ['text' => str_repeat('x', 200)]);
            $this->fail('PayloadTooLarge was not thrown.');
        } catch (PayloadTooLarge $e) {
            $this->assertSame('OrderShipped', $e->event);
        }

        $this->assertSame([], $this->pusher->calls);
    }

    public function test_small_payload_is_sent_once(): void
    {
        $broadcaster = new GuardedPusherBroadcaster($this->pusher, new PayloadGuard(10000, 1024), 'throw');

        $broadcaster->broadcast(['orders.1', 'orders.2'], 'OrderShipped', ['id' => 1, 'socket' => '123.456']);

        $this->assertSame([[
            'channels' => ['orders.1', 'orders.2'],
            'event' => 'OrderShipped',
            'data' => ['id' => 1],
            'params' => ['socket_id' => '123.456'],
        ]], $this->pusher->calls);
    }

    public function test_one_oversized_chunk_stops_every_trigger(): void
    {
        // The plain channel fits and would be triggered first; the encrypted one, sent last, does not.
        $broadcaster = new GuardedPusherBroadcaster($this->pusher, new PayloadGuard(1124, 1024), 'throw');
        $channels = ['orders.1', 'private-encrypted-'.str_repeat('x', 200)];

        $this->expectException(PayloadTooLarge::class);

        try {
            $broadcaster->broadcast($channels, 'OrderShipped', ['id' => 1]);
        } finally {
            $this->assertSame([], $this->pusher->calls);
        }
    }

    public function test_large_channel_lists_are_measured_per_chunk(): void
    {
        $broadcaster = new GuardedPusherBroadcaster($this->pusher, new PayloadGuard(10000, 1024), 'throw');
        $channels = array_map(static fn (int $i): string => "orders.{$i}", range(1, 150));

        $broadcaster->broadcast($channels, 'OrderShipped', ['id' => 1]);

        $this->assertCount(2, $this->pusher->calls);
    }

    public function test_channel_auth_still_works_through_guarded_driver(): void
    {
        $this->configureReverbConnection();

        $manager = app(BroadcastManager::class);
        GuardedPusherBroadcaster::extend($manager, new PayloadGuard(10000, 1024), 'report');
        $manager->purge('reverb');

        Broadcast::channel('orders.{id}', static fn (): bool => true);

        $driver = Broadcast::driver('reverb');
        $this->assertInstanceOf(GuardedPusherBroadcaster::class, $driver);

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-orders.1',
            'socket_id' => '1.1',
        ]);
        $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 1]));

        $response = $driver->auth($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('auth', $response);
    }

    public function test_extend_keeps_jsonp_setting(): void
    {
        $this->configureReverbConnection(['jsonp' => true]);

        $manager = app(BroadcastManager::class);
        GuardedPusherBroadcaster::extend($manager, new PayloadGuard(10000, 1024), 'report');
        $manager->purge('reverb');

        Broadcast::channel('orders.{id}', static fn (): bool => true);

        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-orders.1',
            'socket_id' => '1.1',
            'callback' => 'cb',
        ]);
        $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 1]));

        $driver = $manager->connection('reverb');
        $this->assertInstanceOf(GuardedPusherBroadcaster::class, $driver);

        $response = $driver->auth($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertStringStartsWith('/**/cb(', (string) $response->getContent());
    }

    public function test_from_config_prefers_max_bytes(): void
    {
        config([
            'reverb-kit.publish.max_bytes' => 5000,
            'reverb-kit.publish.header_allowance' => 100,
            'reverb.servers.reverb.max_request_size' => 20000,
        ]);

        $this->assertFitsExactly(PayloadGuard::fromConfig(config()), 5000, 100);
    }

    public function test_from_config_falls_back_to_reverb_server_limit(): void
    {
        config([
            'reverb-kit.publish.max_bytes' => null,
            'reverb-kit.publish.header_allowance' => 1024,
            'reverb-kit.server' => 'edge',
            'reverb.servers.edge.max_request_size' => '20000',
        ]);

        $this->assertFitsExactly(PayloadGuard::fromConfig(config()), 20000, 1024);
    }

    public function test_from_config_falls_back_to_ten_thousand(): void
    {
        config([
            'reverb-kit.publish.max_bytes' => null,
            'reverb-kit.publish.header_allowance' => null,
            'reverb' => null,
        ]);

        $this->assertFitsExactly(PayloadGuard::fromConfig(config()), 10000, 1024);
    }

    /**
     * Proves the guard's limit and allowance by probing the boundary byte.
     */
    private function assertFitsExactly(PayloadGuard $guard, int $limit, int $allowance): void
    {
        $base = $guard->measure(['c'], 'e', ['t' => '']);
        $fits = ['t' => str_repeat('x', $limit - $allowance - $base)];
        $over = ['t' => str_repeat('x', $limit - $allowance - $base + 1)];

        $guard->assertFits(['c'], 'e', $fits);

        try {
            $guard->assertFits(['c'], 'e', $over);
            $this->fail('PayloadTooLarge was not thrown.');
        } catch (PayloadTooLarge $e) {
            $this->assertSame($limit, $e->limit);
            $this->assertSame($limit + 1, $e->bytes);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function configureReverbConnection(array $extra = []): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => array_merge([
                'driver' => 'reverb',
                'key' => 'key',
                'secret' => 'secret',
                'app_id' => '1',
                'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
            ], $extra),
        ]);
    }
}
