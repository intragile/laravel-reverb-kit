<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Support\Hosts;

/**
 * Checks that the Reverb server binds to an IP address.
 *
 * ReactPHP's TCP server only accepts an IP literal and throws on a hostname, so a public or
 * service name in the bind address stops `reverb:start` from booting at all.
 */
final class ServerBindsToIp implements Rule
{
    private const NAME = 'server-bind';

    private const HINT = 'REVERB_SERVER_HOST must be an IP to bind to; the public name belongs in REVERB_HOST / VITE_REVERB_HOST.';

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

        $host = $settings->server['host'] ?? null;

        if (is_string($host) && Hosts::isIpLiteral($host)) {
            return [Finding::ok(self::NAME, "Reverb binds to {$host}")];
        }

        $shown = is_string($host) && trim($host) !== '' ? "\"{$host}\"" : 'an empty value';

        return [Finding::failed(self::NAME, "Reverb server host is {$shown}, which is not an IP address the server can bind to", self::HINT)];
    }
}
