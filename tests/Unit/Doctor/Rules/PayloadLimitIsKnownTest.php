<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use Illuminate\Config\Repository;
use IntrAgile\ReverbKit\Doctor\Rules\PayloadLimitIsKnown;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class PayloadLimitIsKnownTest extends TestCase
{
    public function test_name(): void
    {
        $this->assertSame('payload-limit', (new PayloadLimitIsKnown)->name());
    }

    public function test_reports_limits_ok(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(
            server: ['max_request_size' => null],
            apps: [['app_id' => 'app-1']],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertStringContainsString('max_request_size 10000', $findings[0]->message);
        $this->assertStringContainsString('app-1 max_message_size 10000', $findings[0]->message);
    }

    public function test_message_size_above_request_size_warns(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(apps: [
            ['app_id' => 'app-1', 'max_message_size' => 50000],
            ['app_id' => 'app-2', 'max_message_size' => '10000'],
        ]));

        $this->assertCount(2, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertSame(Status::Warning, $findings[1]->status);
        $this->assertStringContainsString('app-1', $findings[1]->message);
        $this->assertStringContainsString('the wrong variable was raised', $findings[1]->message);
    }

    public function test_publish_max_bytes_above_request_size_warns(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(server: ['max_request_size' => '15000'], publishMaxBytes: 20000));

        $this->assertCount(2, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertSame(Status::Warning, $findings[1]->status);
        $this->assertStringContainsString('20000', $findings[1]->message);
        $this->assertStringContainsString('15000', $findings[1]->message);
    }

    public function test_publish_max_bytes_within_limit_is_ok(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(publishMaxBytes: 10000));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
    }

    public function test_null_publish_max_bytes_is_ignored(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(server: ['max_request_size' => 100], publishMaxBytes: null));

        $this->assertCount(2, $findings);
        $this->assertSame(Status::Warning, $findings[1]->status);
        $this->assertStringContainsString('max_message_size', $findings[1]->message);
    }

    public function test_publish_max_bytes_is_read_from_config(): void
    {
        $settings = ReverbSettings::fromConfig(new Repository([
            'broadcasting' => [
                'default' => 'reverb',
                'connections' => ['reverb' => ['driver' => 'reverb']],
            ],
            'reverb' => [
                'servers' => ['reverb' => ['max_request_size' => 10000]],
                'apps' => ['apps' => []],
            ],
            'reverb-kit' => ['publish' => ['max_bytes' => 50000]],
        ]), '/nonexistent/.env');

        $findings = (new PayloadLimitIsKnown)->check($settings);

        $this->assertSame(50000, $settings->publishMaxBytes);
        $this->assertCount(2, $findings);
        $this->assertSame(Status::Warning, $findings[1]->status);
        $this->assertStringContainsString('publish.max_bytes (50000)', $findings[1]->message);
    }

    public function test_skips_when_not_in_use(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(defaultConnection: 'log'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    public function test_skips_without_reverb_config(): void
    {
        $findings = (new PayloadLimitIsKnown)->check($this->settings(reverbConfigLoaded: false));

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
        ?int $publishMaxBytes = null,
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
            publishMaxBytes: $publishMaxBytes,
        );
    }
}
