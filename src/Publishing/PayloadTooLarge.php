<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Publishing;

use RuntimeException;

/**
 * A broadcast whose publish request would exceed the Reverb server's max_request_size.
 */
final class PayloadTooLarge extends RuntimeException
{
    /**
     * @param  int  $bytes  measured body plus the header allowance
     * @param  int  $limit  the configured maximum request size
     * @param  list<string>  $channels  the channels of the rejected publish request
     */
    public function __construct(
        public readonly int $bytes,
        public readonly int $limit,
        public readonly string $event,
        public readonly array $channels,
    ) {
        parent::__construct(sprintf(
            'Broadcast "%s" is %d bytes, over the %d-byte Reverb request limit (raise REVERB_MAX_REQUEST_SIZE or send less).',
            $event,
            $bytes,
            $limit,
        ));
    }
}
