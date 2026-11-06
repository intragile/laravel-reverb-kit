<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * Checks the installed laravel/reverb version against known security advisories.
 */
final class ReverbIsPatched implements Rule
{
    private const NAME = 'reverb-version';

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

        if ($settings->reverbVersion === null) {
            return [Finding::skipped(self::NAME, 'laravel/reverb is not installed')];
        }

        $version = ltrim($settings->reverbVersion, 'vV');
        $comparable = self::comparable($version);

        if ($comparable === null) {
            return [Finding::skipped(self::NAME, "laravel/reverb {$version} is a branch install and cannot be checked against advisories")];
        }

        if (version_compare($comparable, '1.4.0', '<')) {
            return [Finding::failed(
                self::NAME,
                "laravel/reverb {$version} is affected by CVE-2024-50347",
                'upgrade laravel/reverb to 1.7.0 or later',
            )];
        }

        if (version_compare($comparable, '1.7.0', '<')) {
            $scaling = $settings->server['scaling'] ?? null;
            $enabled = is_array($scaling) && filter_var($scaling['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

            return $enabled
                ? [Finding::failed(self::NAME, "laravel/reverb {$version} is affected by CVE-2026-23524 and scaling is enabled", 'upgrade laravel/reverb to 1.7.0 or later')]
                : [Finding::warning(self::NAME, "laravel/reverb {$version} is affected by CVE-2026-23524 once scaling is on: upgrade before enabling scaling", 'upgrade laravel/reverb to 1.7.0 or later')];
        }

        return [Finding::ok(self::NAME, "laravel/reverb {$version} has no known advisories")];
    }

    /**
     * Turns a Composer pretty version into one version_compare() orders correctly.
     *
     * A minor branch alias such as 1.7.x-dev compares as the newest release on that branch.
     * Other branch installs (1.x-dev, dev-main) carry no comparable version and yield null.
     */
    private static function comparable(string $version): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\.x-dev$/i', $version, $match) === 1) {
            return "{$match[1]}.{$match[2]}.".PHP_INT_MAX;
        }

        if (preg_match('/^\d+\.\d+(\.\d+)*(-(alpha|beta|rc)\d*)?$/i', $version) !== 1) {
            return null;
        }

        return $version;
    }
}
