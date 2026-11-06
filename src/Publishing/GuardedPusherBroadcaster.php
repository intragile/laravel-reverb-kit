<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Publishing;

use Closure;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Arr;
use Pusher\Pusher;
use RuntimeException;

/**
 * The stock Reverb/Pusher broadcaster with a size check in front of every publish request.
 *
 * Channel registration and authorization are inherited unchanged.
 */
final class GuardedPusherBroadcaster extends PusherBroadcaster
{
    public const MODE_REPORT = 'report';

    public const MODE_THROW = 'throw';

    public function __construct(
        Pusher $pusher,
        private readonly PayloadGuard $guard,
        private readonly string $mode,
        bool $allowJsonp = false,
    ) {
        parent::__construct($pusher, $allowJsonp);
    }

    /**
     * Replaces the `reverb` driver of the manager with a guarded one built around the client of
     * the framework's own reverb driver.
     */
    public static function extend(BroadcastManager $manager, PayloadGuard $guard, string $mode): void
    {
        // Laravel 13 rebinds the callback's scope to BroadcastManager, so the closure refers to
        // this class by its full name and never through self or static.
        $manager->extend('reverb', function (mixed $app, array $config) use ($manager, $guard, $mode): GuardedPusherBroadcaster {
            // createReverbDriver() is protected (Laravel 12 and 13) and Laravel 12 does not bind
            // the callback to the manager, so it is reached through a closure bound to the manager.
            // Building the stock driver keeps every client setting the framework applies (Guzzle
            // options, logger) instead of duplicating that construction here.
            $factory = Closure::bind(
                fn (array $config): mixed => $this->createReverbDriver($config),
                $manager,
                BroadcastManager::class,
            );

            $stock = $factory($config);

            if (! $stock instanceof PusherBroadcaster) {
                throw new RuntimeException('The framework reverb driver is not a PusherBroadcaster; the payload guard cannot wrap it.');
            }

            return new GuardedPusherBroadcaster($stock->getPusher(), $guard, $mode, (bool) ($config['jsonp'] ?? false));
        });
    }

    /**
     * Checks every publish request the parent would send before sending any of them, so a
     * broadcast goes out to all of its channels or to none. Encrypted channels are measured with
     * their ciphertext (see PayloadGuard::measure()), not with the plaintext payload.
     *
     * @param  array<array-key, mixed>  $channels
     * @param  string  $event
     * @param  array<array-key, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        try {
            $this->assertFits($channels, $event, $payload);
        } catch (PayloadTooLarge $e) {
            if ($this->mode === self::MODE_THROW) {
                throw $e;
            }

            report($e);

            return;
        }

        parent::broadcast($channels, $event, $payload);
    }

    /**
     * Splits the broadcast the way PusherBroadcaster::broadcast() does: plain channels in
     * chunks of 100, each private-encrypted-* channel on its own.
     *
     * @param  array<array-key, mixed>  $channels
     * @param  array<array-key, mixed>  $payload
     */
    private function assertFits(array $channels, string $event, array $payload): void
    {
        $socket = Arr::pull($payload, 'socket');
        $socketId = is_string($socket) ? $socket : null;

        $plain = [];
        $encrypted = [];

        foreach ($this->formatChannels($channels) as $channel) {
            if (! is_string($channel)) {
                continue;
            }

            if (str_starts_with($channel, 'private-encrypted-')) {
                $encrypted[] = $channel;
            } else {
                $plain[] = $channel;
            }
        }

        foreach (array_chunk($plain, 100) as $chunk) {
            $this->guard->assertFits($chunk, $event, $payload, $socketId);
        }

        foreach ($encrypted as $channel) {
            $this->guard->assertFits([$channel], $event, $payload, $socketId);
        }
    }
}
