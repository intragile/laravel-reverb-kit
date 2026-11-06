<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Support\Hosts;

/**
 * Checks the VITE_REVERB_* values Laravel Echo is built with against what a browser can use.
 *
 * The frontend host is deliberately not compared with the connection host: the backend usually
 * publishes to an internal service name while browsers connect through a public hostname.
 * Values that reference other variables (`${...}` or `$NAME`) are resolved by the frontend build, so they
 * are reported as such and not judged.
 */
final class FrontendEnvMatches implements Rule
{
    private const NAME = 'frontend-env';

    private const KEY = 'VITE_REVERB_APP_KEY';

    private const HOST = 'VITE_REVERB_HOST';

    private const PORT = 'VITE_REVERB_PORT';

    private const SCHEME = 'VITE_REVERB_SCHEME';

    public function name(): string
    {
        return self::NAME;
    }

    public function check(ReverbSettings $settings): array
    {
        if (! $settings->inUse()) {
            return [Finding::skipped(self::NAME, "The {$settings->connectionName} connection is not the default broadcaster")];
        }

        if ($settings->frontendEnv === null) {
            $source = $settings->frontendEnvSource ?? 'the frontend env file';

            return [Finding::skipped(self::NAME, "No VITE_REVERB_* values found in {$source}")];
        }

        $values = [];
        $interpolated = [];

        foreach ($settings->frontendEnv as $name => $value) {
            if ($this->isInterpolated($value)) {
                $interpolated[] = $name;
            } else {
                $values[$name] = $value;
            }
        }

        $judged = static fn (string $name): bool => ! in_array($name, $interpolated, true);

        $findings = [];

        if ($interpolated !== []) {
            $findings[] = Finding::ok(self::NAME, implode(', ', $interpolated).' interpolated, checked at build time');
        }

        if ($judged(self::KEY)) {
            $finding = $this->checkKey($values[self::KEY] ?? null, $settings);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        if ($judged(self::HOST)) {
            $finding = $this->checkHost($values[self::HOST] ?? null, $settings->production);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        if ($judged(self::PORT) && $judged(self::SCHEME)) {
            $finding = $this->checkSchemeAndPort($values[self::SCHEME] ?? null, $values[self::PORT] ?? null);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        if ($findings === []) {
            $findings[] = Finding::ok(self::NAME, "VITE_REVERB_* values are consistent with the {$settings->connectionName} connection");
        }

        return $findings;
    }

    /**
     * Whether the frontend build expands a variable reference in the value.
     *
     * Vite's dotenv-expand resolves both `${NAME}` and the bare `$NAME` form; a bare `$`
     * escaped as `\$` stays literal.
     */
    private function isInterpolated(string $value): bool
    {
        return str_contains($value, '${') || preg_match('/(?<!\\\\)\$[A-Za-z_]/', $value) === 1;
    }

    private function checkKey(?string $key, ReverbSettings $settings): ?Finding
    {
        $expected = $settings->connection['key'] ?? null;

        // A missing connection key is reported by the credentials rule; comparing against it
        // here would only repeat that failure.
        if (! is_string($expected) || $expected === '') {
            return null;
        }

        $hint = 'Set VITE_REVERB_APP_KEY to the REVERB_APP_KEY of this app or to "${REVERB_APP_KEY}"';

        if ($key === null || $key === '') {
            return Finding::failed(self::NAME, 'VITE_REVERB_APP_KEY is not set, so Echo connects without an app key', $hint);
        }

        if ($key !== $expected) {
            return Finding::failed(
                self::NAME,
                "VITE_REVERB_APP_KEY does not match the key of the {$settings->connectionName} connection",
                $hint,
            );
        }

        return null;
    }

    private function checkHost(?string $host, bool $production): ?Finding
    {
        $hint = 'Set VITE_REVERB_HOST to the public hostname browsers use to reach Reverb';

        if (Hosts::isBindAddress($host)) {
            $shown = $host === null ? 'not set' : "'{$host}'";

            return Finding::failed(self::NAME, "VITE_REVERB_HOST is {$shown}, which is not an address a browser can connect to", $hint);
        }

        if ($host === null || ! $production) {
            return null;
        }

        $internal = Hosts::isLoopback($host)
            || (! Hosts::isIpLiteral($host) && ! str_contains(trim($host, '.'), '.'));

        if ($internal) {
            return Finding::failed(self::NAME, "VITE_REVERB_HOST is '{$host}' in production, browsers cannot resolve it", $hint);
        }

        return null;
    }

    private function checkSchemeAndPort(?string $scheme, ?string $port): ?Finding
    {
        if ($scheme === null || $port === null) {
            return null;
        }

        $scheme = strtolower(trim($scheme));
        $port = trim($port);

        if (($port === '443' && $scheme === 'http') || ($port === '80' && $scheme === 'https')) {
            return Finding::warning(
                self::NAME,
                "VITE_REVERB_SCHEME is {$scheme} but VITE_REVERB_PORT is {$port}",
                'Use https with port 443, or http with port 80, unless a proxy really serves this combination',
            );
        }

        return null;
    }
}
