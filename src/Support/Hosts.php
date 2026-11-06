<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Support;

/**
 * Classifies the host values that appear in Reverb configuration.
 *
 * The same setting can legitimately hold a bind address, an internal service name or a public
 * hostname depending on its role, so the rules need to tell these apart without DNS lookups.
 */
final class Hosts
{
    /**
     * An address a server listens on rather than one a client can connect to: empty, or the
     * IPv4/IPv6 wildcard.
     */
    public static function isBindAddress(?string $host): bool
    {
        if ($host === null || trim($host) === '') {
            return true;
        }

        return in_array(self::unbracket($host), ['0.0.0.0', '::'], true);
    }

    public static function isIpLiteral(string $host): bool
    {
        return filter_var(self::unbracket($host), FILTER_VALIDATE_IP) !== false;
    }

    public static function isLoopback(string $host): bool
    {
        $host = strtolower(self::unbracket($host));

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }

    /**
     * A name a browser on the internet can resolve: not an IP, not loopback, and fully
     * qualified. Single-label names such as `reverb` only resolve inside a container network.
     */
    public static function isPublicHostname(string $host): bool
    {
        $host = trim($host);

        return $host !== ''
            && ! self::isIpLiteral($host)
            && ! self::isLoopback($host)
            && str_contains(trim($host, '.'), '.');
    }

    public static function hostOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function unbracket(string $host): string
    {
        $host = trim($host);

        return str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
    }
}
