<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * Reports the payload limits Reverb enforces and flags limits that cannot take effect.
 *
 * Reverb rejects any HTTP request above `max_request_size` before the per-app
 * `max_message_size` is ever consulted, so raising only the message size has no effect.
 */
final class PayloadLimitIsKnown implements Rule
{
    private const NAME = 'payload-limit';

    private const DEFAULT_LIMIT = 10000;

    public function name(): string
    {
        return self::NAME;
    }

    public function check(ReverbSettings $settings): array
    {
        if (! $settings->inUse()) {
            return [Finding::skipped(self::NAME, "The {$settings->connectionName} connection is not the default broadcaster")];
        }

        if (! $settings->reverbConfigLoaded) {
            return [Finding::skipped(self::NAME, 'The Reverb server configuration is not loaded')];
        }

        $requestSize = self::bytes($settings->server['max_request_size'] ?? null);
        $summary = ["max_request_size {$requestSize} bytes"];
        $warnings = [];

        $publishMaxBytes = $settings->publishMaxBytes;

        if ($publishMaxBytes !== null && $publishMaxBytes > $requestSize) {
            $warnings[] = Finding::warning(
                self::NAME,
                "publish.max_bytes ({$publishMaxBytes}) is above max_request_size ({$requestSize}): Reverb rejects payloads the guard lets through",
                'raise REVERB_MAX_REQUEST_SIZE or lower reverb-kit.publish.max_bytes',
            );
        }

        foreach ($settings->apps as $index => $app) {
            $id = $app['app_id'] ?? null;
            $id = is_scalar($id) ? (string) $id : "#{$index}";
            $messageSize = self::bytes($app['max_message_size'] ?? null);
            $summary[] = "app {$id} max_message_size {$messageSize}";

            if ($messageSize > $requestSize) {
                $warnings[] = Finding::warning(
                    self::NAME,
                    "app {$id} max_message_size ({$messageSize}) is above max_request_size ({$requestSize}): the wrong variable was raised",
                    'raise REVERB_MAX_REQUEST_SIZE as well; the request limit is enforced first',
                );
            }
        }

        return [Finding::ok(self::NAME, implode(', ', $summary)), ...$warnings];
    }

    private static function bytes(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : self::DEFAULT_LIMIT;
    }
}
