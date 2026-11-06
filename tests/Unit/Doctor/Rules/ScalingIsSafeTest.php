<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Rules\ScalingIsSafe;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class ScalingIsSafeTest extends TestCase
{
    private const HINT = 'set REVERB_SCALING_CHANNEL per deployment, e.g. reverb_{app}_{env}';

    public function test_name(): void
    {
        $this->assertSame('scaling', (new ScalingIsSafe)->name());
    }

    public function test_two_replicas_without_scaling_fails(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings(replicas: 2));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Failed, $findings[0]->status);
        $this->assertStringContainsString("clients on different replicas will miss each other's messages", $findings[0]->message);
    }

    public function test_default_channel_on_shared_redis_fails(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings(
            server: ['scaling' => ['enabled' => true, 'channel' => 'reverb']],
            replicas: 2,
            sharedRedis: true,
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Failed, $findings[0]->status);
        $this->assertSame(self::HINT, $findings[0]->hint);
    }

    public function test_default_channel_warns(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings(
            server: ['scaling' => ['enabled' => true]],
            replicas: 2,
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Warning, $findings[0]->status);
        $this->assertSame(self::HINT, $findings[0]->hint);
    }

    public function test_custom_channel_is_ok(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings(
            server: ['scaling' => ['enabled' => true, 'channel' => 'reverb_app_prod']],
            replicas: 3,
            sharedRedis: true,
        ));

        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertSame('scaling on, channel reverb_app_prod', $findings[0]->message);
    }

    public function test_single_replica_without_scaling_is_ok(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings());

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertSame('scaling off, 1 replica', $findings[0]->message);
    }

    public function test_string_enabled_flag_is_respected(): void
    {
        $off = (new ScalingIsSafe)->check($this->settings(
            server: ['scaling' => ['enabled' => 'false', 'channel' => 'reverb']],
            replicas: 2,
        ));
        $on = (new ScalingIsSafe)->check($this->settings(
            server: ['scaling' => ['enabled' => 'true', 'channel' => 'reverb_app_prod']],
            replicas: 2,
        ));

        $this->assertSame(Status::Failed, $off[0]->status);
        $this->assertSame(Status::Ok, $on[0]->status);
    }

    public function test_skips_when_not_in_use(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings(defaultConnection: 'log'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    public function test_skips_without_reverb_config(): void
    {
        $findings = (new ScalingIsSafe)->check($this->settings(reverbConfigLoaded: false));

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
