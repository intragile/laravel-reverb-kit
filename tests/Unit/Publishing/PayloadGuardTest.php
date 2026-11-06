<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Publishing;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use IntrAgile\ReverbKit\Publishing\PayloadGuard;
use IntrAgile\ReverbKit\Publishing\PayloadTooLarge;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Pusher\Pusher;

final class PayloadGuardTest extends TestCase
{
    private const ALLOWANCE = 1024;

    public function test_payload_exactly_at_limit_passes(): void
    {
        $channels = ['orders.1'];
        $payload = ['id' => 1, 'status' => 'shipped'];

        $measured = (new PayloadGuard(PHP_INT_MAX, self::ALLOWANCE))->measure($channels, 'OrderShipped', $payload);
        $guard = new PayloadGuard($measured + self::ALLOWANCE, self::ALLOWANCE);

        $guard->assertFits($channels, 'OrderShipped', $payload);

        $this->addToAssertionCount(1);
    }

    public function test_one_byte_over_limit_is_rejected(): void
    {
        $channels = ['orders.1', 'orders.2'];
        $payload = ['id' => 1, 'status' => 'shipped'];

        $measured = (new PayloadGuard(PHP_INT_MAX, self::ALLOWANCE))->measure($channels, 'OrderShipped', $payload);
        $limit = $measured + self::ALLOWANCE - 1;

        try {
            (new PayloadGuard($limit, self::ALLOWANCE))->assertFits($channels, 'OrderShipped', $payload);
            $this->fail('PayloadTooLarge was not thrown.');
        } catch (PayloadTooLarge $e) {
            $bytes = $measured + self::ALLOWANCE;

            $this->assertSame(
                "Broadcast \"OrderShipped\" is {$bytes} bytes, over the {$limit}-byte Reverb request limit (raise REVERB_MAX_REQUEST_SIZE or send less).",
                $e->getMessage(),
            );
            $this->assertSame($bytes, $e->bytes);
            $this->assertSame($limit, $e->limit);
            $this->assertSame('OrderShipped', $e->event);
            $this->assertSame(['orders.1', 'orders.2'], $e->channels);
        }
    }

    public function test_channel_list_counts(): void
    {
        $guard = new PayloadGuard(PHP_INT_MAX, self::ALLOWANCE);
        $payload = ['id' => 1];

        $one = $guard->measure(['orders.1'], 'OrderShipped', $payload);
        $two = $guard->measure(['orders.1', 'orders.2'], 'OrderShipped', $payload);

        $this->assertGreaterThan($one, $two);
        $this->assertSame($this->sentBodyLength(['orders.1'], 'OrderShipped', $payload), $one);
        $this->assertSame(
            $this->sentBodyLength(['orders.1', 'orders.2', 'orders.3'], 'OrderShipped', $payload),
            $guard->measure(['orders.1', 'orders.2', 'orders.3'], 'OrderShipped', $payload),
        );
    }

    public function test_unicode_payload_measured_in_bytes(): void
    {
        $guard = new PayloadGuard(PHP_INT_MAX, self::ALLOWANCE);
        $payload = ['name' => 'Árvíztűrő tükörfúrógép', 'path' => 'a/b/c'];

        $measured = $guard->measure(['orders.1'], 'OrderShipped', $payload);

        $this->assertSame($this->sentBodyLength(['orders.1'], 'OrderShipped', $payload), $measured);
        $this->assertGreaterThan(strlen('Árvíztűrő tükörfúrógép') + strlen('a/b/c'), $measured);
    }

    public function test_socket_id_counts(): void
    {
        $guard = new PayloadGuard(PHP_INT_MAX, self::ALLOWANCE);
        $payload = ['id' => 1];

        $without = $guard->measure(['orders.1'], 'OrderShipped', $payload);
        $with = $guard->measure(['orders.1'], 'OrderShipped', $payload, '123.456');

        $this->assertGreaterThan($without, $with);
        $this->assertSame($this->sentBodyLength(['orders.1'], 'OrderShipped', $payload, '123.456'), $with);
    }

    public function test_exception_reports_measured_plus_allowance_against_max_bytes(): void
    {
        $guard = new PayloadGuard(100, 50);
        $payload = ['text' => str_repeat('x', 200)];
        $measured = $guard->measure(['orders.1'], 'Big', $payload);

        try {
            $guard->assertFits(['orders.1'], 'Big', $payload);
            $this->fail('PayloadTooLarge was not thrown.');
        } catch (PayloadTooLarge $e) {
            $this->assertSame($measured + 50, $e->bytes);
            $this->assertSame(100, $e->limit);
        }
    }

    public function test_encrypted_channel_is_measured_with_ciphertext_and_never_below_the_sent_body(): void
    {
        $guard = new PayloadGuard(PHP_INT_MAX, self::ALLOWANCE);
        $channel = 'private-encrypted-orders';
        $payload = ['text' => str_repeat('x', 3000), 'path' => 'a/b'];

        $plain = $guard->measure(['orders'], 'OrderShipped', $payload);
        $measured = $guard->measure([$channel], 'OrderShipped', $payload);
        $this->assertGreaterThan($plain + 1000, $measured);

        for ($i = 0; $i < 50; $i++) {
            $sent = $this->sentBody([$channel], 'OrderShipped', $payload, '123.456');
            $measuredWithSocket = $guard->measure([$channel], 'OrderShipped', $payload, '123.456');

            // Everything but the escaped slashes is exact; each slash adds 3 bytes on the wire.
            $slashes = substr_count($sent, '\\\\\\/');
            $exact = strlen($sent) - 3 * $slashes;

            $this->assertGreaterThanOrEqual(strlen($sent), $measuredWithSocket);
            $this->assertLessThan($exact + 600, $measuredWithSocket);
        }
    }

    public function test_encrypted_payload_that_fits_only_as_plaintext_is_rejected(): void
    {
        $guard = new PayloadGuard(10000, self::ALLOWANCE);
        $payload = ['text' => str_repeat('x', 7180)];

        $guard->assertFits(['orders'], 'OrderShipped', $payload);

        $this->expectException(PayloadTooLarge::class);
        $guard->assertFits(['private-encrypted-orders'], 'OrderShipped', $payload);
    }

    /**
     * The length of the body a real pusher client sends for the same trigger() call.
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function sentBodyLength(array $channels, string $event, array $payload, ?string $socketId = null): int
    {
        return strlen($this->sentBody($channels, $event, $payload, $socketId));
    }

    /**
     * The body a real pusher client, with an encryption master key, sends for the same trigger() call.
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function sentBody(array $channels, string $event, array $payload, ?string $socketId = null): string
    {
        if (! class_exists(Pusher::class)) {
            $this->markTestSkipped('pusher/pusher-php-server is not installed.');
        }

        $sent = null;
        $handler = new MockHandler([static function (RequestInterface $request) use (&$sent): Response {
            $sent = (string) $request->getBody();

            return new Response(200, [], '{}');
        }]);

        $pusher = new Pusher('key', 'secret', '1', [
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            'encryption_master_key_base64' => base64_encode(str_repeat('k', 32)),
        ], new Client(['handler' => HandlerStack::create($handler)]));
        $pusher->trigger($channels, $event, $payload, $socketId !== null ? ['socket_id' => $socketId] : []);

        $this->assertIsString($sent);

        return $sent;
    }
}
