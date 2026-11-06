<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Rules\AllowedOriginsAreRestricted;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class AllowedOriginsAreRestrictedTest extends TestCase
{
    private const HINT = "config/reverb.php hard-codes ['*']; list the frontend origin(s)";

    public function test_name(): void
    {
        $this->assertSame('allowed-origins', (new AllowedOriginsAreRestricted)->name());
    }

    public function test_star_origin_warns_in_production_only(): void
    {
        $apps = [['app_id' => 'app-1', 'allowed_origins' => ['*']]];

        $production = (new AllowedOriginsAreRestricted)->check($this->settings(apps: $apps));
        $local = (new AllowedOriginsAreRestricted)->check($this->settings(environment: 'local', production: false, apps: $apps));

        $this->assertCount(1, $production);
        $this->assertSame(Status::Warning, $production[0]->status);
        $this->assertStringContainsString('app-1', $production[0]->message);
        $this->assertSame(self::HINT, $production[0]->hint);

        $this->assertCount(1, $local);
        $this->assertSame(Status::Ok, $local[0]->status);
    }

    public function test_explicit_origins_are_ok(): void
    {
        $findings = (new AllowedOriginsAreRestricted)->check($this->settings());

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
    }

    public function test_names_every_offending_app(): void
    {
        $findings = (new AllowedOriginsAreRestricted)->check($this->settings(apps: [
            ['app_id' => 'first', 'allowed_origins' => ['*']],
            ['app_id' => 'clean', 'allowed_origins' => ['app.example.com']],
            ['app_id' => 'second', 'allowed_origins' => ['app.example.com', '*']],
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Warning, $findings[0]->status);
        $this->assertStringContainsString('first', $findings[0]->message);
        $this->assertStringContainsString('second', $findings[0]->message);
        $this->assertStringNotContainsString('clean', $findings[0]->message);
    }

    public function test_skips_when_not_in_use(): void
    {
        $findings = (new AllowedOriginsAreRestricted)->check($this->settings(defaultConnection: 'log'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    public function test_skips_without_reverb_config(): void
    {
        $findings = (new AllowedOriginsAreRestricted)->check($this->settings(reverbConfigLoaded: false));

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
