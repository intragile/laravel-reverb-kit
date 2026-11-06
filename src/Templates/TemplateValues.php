<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Templates;

use Illuminate\Contracts\Config\Repository;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Support\Hosts;

/**
 * Derives the placeholder values of the generator stubs from the application's configuration.
 */
final class TemplateValues
{
    /**
     * The laravel/reverb release that added the `GET {path}/up` HealthCheckController route
     * (v1.0.0-beta11, "Add health check endpoint"; absent in v1.0.0-beta10).
     */
    public const HEALTH_ENDPOINT_SINCE = '1.0.0-beta11';

    public const DEFAULT_IMAGE = 'registry.example.com/app:latest';

    private const DEFAULT_PORT = 8080;

    private const MIN_READ_TIMEOUT = 120;

    /**
     * @param  array<string, string>  $overrides  replace any computed value
     * @return array<string, string>
     */
    public static function from(ReverbSettings $settings, Repository $config, array $overrides = []): array
    {
        $server = $settings->server ?? [];
        $port = is_numeric($server['port'] ?? null) ? (string) (int) $server['port'] : (string) self::DEFAULT_PORT;
        $path = self::normalizePath($overrides['path'] ?? (is_string($server['path'] ?? null) ? $server['path'] : ''));

        $replicas = $overrides['replicas'] ?? (string) $settings->replicas;
        $scaling = is_array($server['scaling'] ?? null) ? $server['scaling'] : [];
        $scalingEnabled = (int) $replicas > 1
            || filter_var($scaling['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $scalingChannel = is_string($scaling['channel'] ?? null) && $scaling['channel'] !== ''
            ? $scaling['channel']
            : 'reverb';

        $values = [
            'upstream_host' => self::string($config->get('reverb-kit.templates.upstream_host')) ?? 'reverb',
            'upstream_port' => $port,
            'path' => $path,
            'public_host' => self::string($config->get('reverb-kit.templates.public_host'))
                ?? Hosts::hostOf(self::string($config->get('app.url')))
                ?? 'localhost',
            'resolver' => self::string($config->get('reverb-kit.templates.resolver')) ?? '127.0.0.11',
            'read_timeout' => (string) self::readTimeout($settings),
            'service' => 'reverb',
            'image' => self::DEFAULT_IMAGE,
            'replicas' => $replicas,
            'scaling_enabled' => $scalingEnabled ? 'true' : 'false',
            'scaling_channel' => $scalingChannel,
            'healthcheck' => self::healthcheck($settings->reverbVersion, $overrides['upstream_port'] ?? $port, $path),
            'update_order' => (int) $replicas > 1 ? 'start-first' : 'stop-first',
            'api_location' => '',
        ];

        return array_merge($values, $overrides, ['path' => $path]);
    }

    /**
     * Whether a laravel/reverb version string is known to serve the `/up` endpoint. Versions
     * that cannot be compared (not installed, `dev-*` branches) are treated as not serving it.
     */
    public static function supportsHealthEndpoint(?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        $version = ltrim(trim($version), 'vV');

        if (preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9.]+)?$/', $version) !== 1) {
            return false;
        }

        return version_compare($version, self::HEALTH_ENDPOINT_SINCE, '>=');
    }

    /**
     * The healthcheck command. It is embedded in a double-quoted YAML scalar and read by the
     * compose interpolator, so it must contain neither double quotes nor `$`.
     */
    private static function healthcheck(?string $version, string $port, string $path): string
    {
        $base = "http://127.0.0.1:{$port}{$path}";

        if (self::supportsHealthEndpoint($version)) {
            return "curl -fsS {$base}/up";
        }

        // Without /up, any HTTP answer proves the server is up: 404 for an unknown route, 426
        // when the WebSocket upgrade is missing. A refused connection yields 000.
        return "curl -s -o /dev/null -w '%{http_code}' {$base}/app | grep -qE '200|404|426'";
    }

    /**
     * Twice the longest ping interval, so an idle but healthy connection is never cut by the
     * proxy, and never below two minutes.
     */
    private static function readTimeout(ReverbSettings $settings): int
    {
        $longest = 0;

        foreach ($settings->apps as $app) {
            $options = is_array($app['options'] ?? null) ? $app['options'] : [];
            $interval = $app['ping_interval'] ?? $options['ping_interval'] ?? null;

            if (is_numeric($interval)) {
                $longest = max($longest, (int) $interval);
            }
        }

        return max(2 * $longest, self::MIN_READ_TIMEOUT);
    }

    /**
     * `''` for the root, otherwise a leading slash and no trailing one.
     */
    private static function normalizePath(string $path): string
    {
        $path = trim(trim($path), '/');

        return $path === '' ? '' : '/'.$path;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
