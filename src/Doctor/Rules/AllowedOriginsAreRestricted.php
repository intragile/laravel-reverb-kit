<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * Warns when a production Reverb app accepts WebSocket connections from any origin.
 */
final class AllowedOriginsAreRestricted implements Rule
{
    private const NAME = 'allowed-origins';

    private const HINT = "config/reverb.php hard-codes ['*']; list the frontend origin(s)";

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

        if (! $settings->production) {
            return [Finding::ok(self::NAME, "Origins are not checked in the {$settings->environment} environment")];
        }

        $open = [];

        foreach ($settings->apps as $index => $app) {
            $origins = $app['allowed_origins'] ?? null;

            if (is_array($origins) && in_array('*', $origins, true)) {
                $id = $app['app_id'] ?? null;
                $open[] = is_scalar($id) ? (string) $id : "#{$index}";
            }
        }

        if ($open !== []) {
            return [Finding::warning(
                self::NAME,
                'Reverb app(s) '.implode(', ', $open).' accept connections from any origin',
                self::HINT,
            )];
        }

        return [Finding::ok(self::NAME, 'Every Reverb app restricts its allowed origins')];
    }
}
