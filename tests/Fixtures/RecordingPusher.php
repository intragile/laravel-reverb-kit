<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Fixtures;

use Pusher\Pusher;

/**
 * A pusher client that records trigger() calls instead of sending them.
 */
final class RecordingPusher extends Pusher
{
    /** @var list<array{channels: mixed, event: string, data: mixed, params: array<mixed>}> */
    public array $calls = [];

    /**
     * @param  array<array-key, string>|string  $channels
     * @param  array<array-key, mixed>  $params
     */
    public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
    {
        $this->calls[] = ['channels' => $channels, 'event' => $event, 'data' => $data, 'params' => $params];

        return (object) [];
    }
}
