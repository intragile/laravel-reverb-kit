<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Rules\ServerBindsToIp;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class ServerBindsToIpTest extends TestCase
{
    private const HINT = 'REVERB_SERVER_HOST must be an IP to bind to; the public name belongs in REVERB_HOST / VITE_REVERB_HOST.';

    public function test_name(): void
    {
        $this->assertSame('server-bind', (new ServerBindsToIp)->name());
    }

    public function test_hostname_bind_fails(): void
    {
        foreach (['ws.example.com', 'reverb'] as $host) {
            $findings = (new ServerBindsToIp)->check($this->settings(server: ['host' => $host]));

            $this->assertCount(1, $findings);
            $this->assertSame(Status::Failed, $findings[0]->status, $host);
            $this->assertStringContainsString($host, $findings[0]->message);
            $this->assertSame(self::HINT, $findings[0]->hint);
        }
    }

    public function test_empty_host_fails(): void
    {
        foreach (['', null, 8080] as $host) {
            $findings = (new ServerBindsToIp)->check($this->settings(server: ['host' => $host]));

            $this->assertSame(Status::Failed, $findings[0]->status);
            $this->assertSame(self::HINT, $findings[0]->hint);
        }
    }

    public function test_wildcard_ipv4_is_ok(): void
    {
        $findings = (new ServerBindsToIp)->check($this->settings());

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertStringContainsString('0.0.0.0', $findings[0]->message);
    }

    public function test_wildcard_ipv6_is_ok(): void
    {
        foreach (['::', '[::]'] as $host) {
            $findings = (new ServerBindsToIp)->check($this->settings(server: ['host' => $host]));

            $this->assertSame(Status::Ok, $findings[0]->status, $host);
        }
    }

    public function test_specific_ip_is_ok(): void
    {
        $findings = (new ServerBindsToIp)->check($this->settings(server: ['host' => '10.0.0.5']));

        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertStringContainsString('10.0.0.5', $findings[0]->message);
    }

    public function test_skips_when_not_in_use(): void
    {
        $findings = (new ServerBindsToIp)->check($this->settings(defaultConnection: 'log'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    public function test_skips_without_reverb_config(): void
    {
        $findings = (new ServerBindsToIp)->check($this->settings(reverbConfigLoaded: false));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    /**
     * @param  array<string, mixed>  $server  merged over the default server block
     * @param  list<array<string, mixed>>|null  $apps
     */
    private function settings(
        string $environment = 'production',
        bool $production = true,
        ?string $defaultConnection = 'reverb',
        bool $reverbConfigLoaded = true,
        array $server = [],
        ?array $apps = null,
        int $replicas = 1,
        bool $sharedRedis = false,
        ?string $reverbVersion = 'v1.12.0',
    ): ReverbSettings {
        return new ReverbSettings(
            environment: $environment,
            production: $production,
            defaultConnection: $defaultConnection,
            connectionName: 'reverb',
            connection: ['driver' => 'reverb'],
            reverbConfigLoaded: $reverbConfigLoaded,
            server: array_replace([
                'host' => '0.0.0.0',
                'port' => 8080,
                'max_request_size' => 10000,
                'scaling' => ['enabled' => false, 'channel' => 'reverb'],
            ], $server),
            apps: $apps ?? [[
                'app_id' => 'app-1',
                'allowed_origins' => ['app.example.com'],
                'max_message_size' => 10000,
            ]],
            replicas: $replicas,
            sharedRedis: $sharedRedis,
            frontendEnv: null,
            frontendEnvSource: null,
            reverbVersion: $reverbVersion,
        );
    }
}
