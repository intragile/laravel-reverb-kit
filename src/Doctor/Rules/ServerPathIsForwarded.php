<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * When Reverb is served under a path prefix, the broadcasting connection must publish to the
 * same prefix or every HTTP publish ends in a 404.
 */
final class ServerPathIsForwarded implements Rule
{
    public function name(): string
    {
        return 'server-path';
    }

    public function check(ReverbSettings $settings): array
    {
        if (! $settings->inUse()) {
            return [Finding::skipped($this->name(), "Broadcasting does not use the {$settings->connectionName} connection.")];
        }

        if (! $settings->reverbConfigLoaded) {
            return [Finding::skipped($this->name(), 'The Reverb configuration is not loaded.')];
        }

        $serverPath = $this->normalize($settings->server['path'] ?? null);

        if ($serverPath === null) {
            return [Finding::ok($this->name(), 'The Reverb server is not served under a path prefix.')];
        }

        $expected = "/{$serverPath}/";
        $connectionPath = $settings->connectionOption('path');

        if ($connectionPath !== $expected) {
            $shown = is_string($connectionPath) && $connectionPath !== '' ? "path '{$connectionPath}'" : 'no path';

            return [Finding::failed(
                $this->name(),
                "The Reverb server is served under /{$serverPath}, but the {$settings->connectionName} connection has {$shown}; it must be exactly '{$expected}'.",
                "Laravel 13's default reverb connection has no path option; add 'path' => '{$expected}' to the connection. The Pusher SDK resolves the publish URL against this path, so without the leading slash the URL is malformed and without the trailing slash the prefix is dropped.",
            )];
        }

        return [Finding::ok($this->name(), "The connection publishes under {$expected}.")];
    }

    /**
     * The server side accepts the prefix with or without slashes, so `/ws`, `ws` and `ws/` name one prefix.
     */
    private function normalize(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path, '/');

        return $path === '' ? null : $path;
    }
}
