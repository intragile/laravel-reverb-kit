<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Support\Hosts;

/**
 * The connection host is where Laravel sends broadcasts, so it must be an address a client can
 * reach. Internal service names such as `reverb` are fine; bind addresses are not.
 */
final class PublishTargetIsAddressable implements Rule
{
    public function name(): string
    {
        return 'publish-target';
    }

    public function check(ReverbSettings $settings): array
    {
        if (! $settings->inUse()) {
            return [Finding::skipped($this->name(), "Broadcasting does not use the {$settings->connectionName} connection.")];
        }

        $host = $settings->connectionOption('host');
        $host = is_string($host) ? $host : null;

        if (Hosts::isBindAddress($host)) {
            $shown = $host === null || trim($host) === '' ? 'empty' : $host;

            return [Finding::failed(
                $this->name(),
                "The {$settings->connectionName} connection host is a bind address ({$shown}).",
                'REVERB_HOST is where Laravel sends broadcasts; the bind address belongs in REVERB_SERVER_HOST.',
            )];
        }

        /** @var string $host */
        $target = $this->target($host, $settings->connectionOption('port'));

        if ($settings->production && Hosts::isLoopback($host)) {
            return [Finding::warning(
                $this->name(),
                "Broadcasts go to the loopback address {$target} in {$settings->environment}.",
                'This only works when Reverb runs in the same container as the application; otherwise use its service name.',
            )];
        }

        return [Finding::ok($this->name(), "Broadcasts go to {$target}.")];
    }

    private function target(string $host, mixed $port): string
    {
        return is_int($port) || (is_string($port) && $port !== '') ? "{$host}:{$port}" : $host;
    }
}
