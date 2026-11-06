<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Doctor;

/**
 * Configuration profiles of real deployments, used to prove the rules do not flag setups that
 * are known to work in production.
 */
final class Profiles
{
    /**
     * Reverb as its own container service behind an nginx proxy: the application publishes to
     * the internal service name over plain HTTP, browsers connect to the public host on 443.
     *
     * @return array<string, mixed>
     */
    public static function containerised(): array
    {
        return [
            'app.env' => 'production',
            'app.url' => 'https://api-core.example.com',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'app-key',
                'secret' => 'app-secret',
                'app_id' => '100001',
                'options' => [
                    'host' => 'reverb',
                    'port' => '8085',
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
                'client_options' => [],
            ],
            'reverb.default' => 'reverb',
            'reverb.servers.reverb' => [
                'host' => '0.0.0.0',
                'port' => '8085',
                'path' => '',
                'hostname' => 'reverb',
                'options' => ['tls' => []],
                'max_request_size' => 10_000,
                'scaling' => [
                    'enabled' => false,
                    'channel' => 'reverb_core_main',
                    'server' => [],
                ],
                'pulse_ingest_interval' => 15,
                'telescope_ingest_interval' => 15,
            ],
            'reverb.apps.apps' => [[
                'key' => 'app-key',
                'secret' => 'app-secret',
                'app_id' => '100001',
                'options' => [
                    'host' => 'reverb',
                    'port' => '8085',
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_connections' => null,
                'max_message_size' => 10_000,
            ]],
            'reverb-kit.topology.replicas' => 1,
            'reverb-kit.topology.shared_redis' => false,
        ];
    }

    /**
     * An application that does not broadcast at all.
     *
     * @return array<string, mixed>
     */
    public static function withoutBroadcasting(): array
    {
        return ['app.env' => 'production', 'broadcasting.default' => 'null'];
    }
}
