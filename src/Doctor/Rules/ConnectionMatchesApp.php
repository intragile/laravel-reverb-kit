<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * The broadcasting connection signs every publish with key, secret and app_id; if they do not
 * belong to one Reverb app, the server rejects the request and the broadcast is lost.
 */
final class ConnectionMatchesApp implements Rule
{
    /**
     * The equivalent diagnostic proposed upstream in laravel/reverb PR #398. When it is
     * installed, this rule defers to it instead of reporting the same problem twice.
     */
    public const UPSTREAM_DIAGNOSTIC = 'Laravel\Reverb\Diagnostics\ReverbBroadcasterCredentialsMatchApp';

    /**
     * The upstream diagnostic extends this class and only runs under laravel/doctor, which
     * laravel/reverb does not require at runtime. Without it the upstream class cannot even be
     * autoloaded, and nothing upstream checks the credentials.
     */
    public const UPSTREAM_DIAGNOSTIC_BASE = 'Laravel\Doctor\Diagnostic';

    private const FIELDS = ['key', 'secret', 'app_id'];

    public function __construct(
        private readonly ?string $upstreamDiagnostic = self::UPSTREAM_DIAGNOSTIC,
        private readonly string $upstreamDiagnosticBase = self::UPSTREAM_DIAGNOSTIC_BASE,
    ) {}

    public function name(): string
    {
        return 'connection-credentials';
    }

    public function check(ReverbSettings $settings): array
    {
        if (! $settings->inUse()) {
            return [Finding::skipped($this->name(), "Broadcasting does not use the {$settings->connectionName} connection.")];
        }

        if ($this->coveredUpstream()) {
            return [Finding::skipped($this->name(), 'covered by laravel/reverb')];
        }

        // Unreachable while inUse() requires a connection; kept as a guard should that change.
        if ($settings->connection === null) {
            return [Finding::failed(
                $this->name(),
                "The broadcasting connection {$settings->connectionName} is not defined.",
                'Define it in config/broadcasting.php.',
            )];
        }

        $credentials = [];

        foreach (self::FIELDS as $field) {
            $value = $this->scalar($settings->connection[$field] ?? null);

            if ($value === null) {
                return [Finding::failed(
                    $this->name(),
                    "The {$settings->connectionName} connection has no {$field}.",
                    'Set REVERB_APP_KEY, REVERB_APP_SECRET and REVERB_APP_ID.',
                )];
            }

            $credentials[$field] = $value;
        }

        if (! $settings->reverbConfigLoaded) {
            return [Finding::ok($this->name(), 'The connection credentials are set; no Reverb app config to compare against.')];
        }

        $app = $this->appByKey($settings->apps, $credentials['key']);

        if ($app === null) {
            return [Finding::failed(
                $this->name(),
                "No Reverb app has the key {$credentials['key']} used by the {$settings->connectionName} connection.",
                'Use the same REVERB_APP_KEY for the broadcasting connection and the Reverb app.',
            )];
        }

        $mismatched = array_values(array_filter(
            ['app_id', 'secret'],
            fn (string $field): bool => $this->scalar($app[$field] ?? null) !== $credentials[$field],
        ));

        if ($mismatched !== []) {
            return [Finding::failed(
                $this->name(),
                'The connection '.implode(' and ', $mismatched)." differ from the Reverb app with key {$credentials['key']}.",
                'Use the same REVERB_APP_ID and REVERB_APP_SECRET for the broadcasting connection and the Reverb app.',
            )];
        }

        return [Finding::ok($this->name(), "The connection credentials match the Reverb app {$credentials['app_id']}.")];
    }

    /**
     * The base class is checked first: autoloading the upstream diagnostic while its parent is
     * missing throws an Error instead of returning false.
     */
    private function coveredUpstream(): bool
    {
        if ($this->upstreamDiagnostic === null || ! class_exists($this->upstreamDiagnosticBase)) {
            return false;
        }

        try {
            return class_exists($this->upstreamDiagnostic);
        } catch (\Error) {
            return false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, mixed>|null
     */
    private function appByKey(array $apps, string $key): ?array
    {
        foreach ($apps as $app) {
            if ($this->scalar($app['key'] ?? null) === $key) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Credentials compare as strings: app_id is often an int in one place and a string in another.
     */
    private function scalar(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
