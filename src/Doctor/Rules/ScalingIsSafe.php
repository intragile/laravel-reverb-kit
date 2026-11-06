<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * Checks horizontal scaling: more than one replica needs the Redis pub/sub bridge, and the
 * pub/sub channel must not be shared with other deployments on the same Redis.
 */
final class ScalingIsSafe implements Rule
{
    private const NAME = 'scaling';

    private const DEFAULT_CHANNEL = 'reverb';

    private const HINT = 'set REVERB_SCALING_CHANNEL per deployment, e.g. reverb_{app}_{env}';

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

        $scaling = $settings->server['scaling'] ?? null;
        $scaling = is_array($scaling) ? $scaling : [];
        $enabled = filter_var($scaling['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $channel = $scaling['channel'] ?? self::DEFAULT_CHANNEL;
        $channel = is_string($channel) && $channel !== '' ? $channel : self::DEFAULT_CHANNEL;

        if (! $enabled) {
            if ($settings->replicas > 1) {
                return [Finding::failed(
                    self::NAME,
                    "{$settings->replicas} replicas with scaling disabled: clients on different replicas will miss each other's messages",
                    'set REVERB_SCALING_ENABLED=true and point every replica at the same Redis',
                )];
            }

            return [Finding::ok(self::NAME, 'scaling off, 1 replica')];
        }

        if ($channel === self::DEFAULT_CHANNEL) {
            return $settings->sharedRedis
                ? [Finding::failed(self::NAME, "Scaling uses the default \"reverb\" channel on a shared Redis: other deployments receive each other's broadcasts", self::HINT)]
                : [Finding::warning(self::NAME, 'Scaling uses the default "reverb" channel, which collides with any other deployment on the same Redis', self::HINT)];
        }

        return [Finding::ok(self::NAME, "scaling on, channel {$channel}")];
    }
}
