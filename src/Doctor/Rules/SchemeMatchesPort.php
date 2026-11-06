<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * Scheme, port and useTLS must tell the same story; a mismatch makes the client speak TLS to a
 * plain socket or the other way round.
 *
 * The connection is checked strictly. App options are what the server advertises to clients,
 * so a mismatch there is reported as a warning only.
 */
final class SchemeMatchesPort implements Rule
{
    public function name(): string
    {
        return 'scheme-port';
    }

    public function check(ReverbSettings $settings): array
    {
        if (! $settings->inUse()) {
            return [Finding::skipped($this->name(), "Broadcasting does not use the {$settings->connectionName} connection.")];
        }

        $options = $settings->connection['options'] ?? null;

        $findings = [$this->evaluate(
            is_array($options) ? $options : [],
            "The {$settings->connectionName} connection",
        )];

        foreach ($settings->apps as $index => $app) {
            $appOptions = $app['options'] ?? null;

            if (! is_array($appOptions)) {
                continue;
            }

            $id = $app['app_id'] ?? null;
            $label = is_string($id) || is_int($id) ? "The Reverb app {$id}" : "The Reverb app #{$index}";

            $finding = $this->evaluate($appOptions, $label);

            $findings[] = $finding->status === Status::Failed
                ? Finding::warning($finding->rule, $finding->message, $finding->hint)
                : $finding;
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $options
     */
    private function evaluate(array $options, string $subject): Finding
    {
        $scheme = is_string($options['scheme'] ?? null) ? strtolower($options['scheme']) : null;
        $port = is_numeric($options['port'] ?? null) ? (int) $options['port'] : null;
        $useTls = is_bool($options['useTLS'] ?? null) ? $options['useTLS'] : null;

        $shown = ($scheme ?? 'no scheme').', port '.($port ?? 'unset').', useTLS '.match ($useTls) {
            true => 'true',
            false => 'false',
            null => 'unset',
        };

        if (($scheme === 'https' && $useTls === false) || ($scheme === 'http' && $useTls === true)) {
            return Finding::failed(
                $this->name(),
                "{$subject} uses {$shown}.",
                'Set useTLS to true for https and to false for http.',
            );
        }

        if (($port === 443 && $scheme === 'http') || ($port === 80 && $scheme === 'https')) {
            return Finding::warning(
                $this->name(),
                "{$subject} uses {$shown}.",
                'Port 443 normally means https and port 80 means http; check REVERB_SCHEME and REVERB_PORT.',
            );
        }

        return Finding::ok($this->name(), "{$subject} uses {$shown}.");
    }
}
