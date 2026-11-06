<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Rules\ReverbIsPatched;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class ReverbIsPatchedTest extends TestCase
{
    public function test_name(): void
    {
        $this->assertSame('reverb-version', (new ReverbIsPatched)->name());
    }

    public function test_missing_reverb_skips(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(reverbVersion: null));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    public function test_version_below_1_4_fails(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(reverbVersion: 'v1.3.9'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Failed, $findings[0]->status);
        $this->assertStringContainsString('CVE-2024-50347', $findings[0]->message);
    }

    public function test_version_below_1_7_with_scaling_fails(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(
            server: ['scaling' => ['enabled' => true, 'channel' => 'reverb_app_prod']],
            reverbVersion: 'v1.6.3',
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Failed, $findings[0]->status);
        $this->assertStringContainsString('CVE-2026-23524', $findings[0]->message);
    }

    public function test_version_below_1_7_without_scaling_warns(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(reverbVersion: 'v1.6.3'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Warning, $findings[0]->status);
        $this->assertStringContainsString('CVE-2026-23524', $findings[0]->message);
        $this->assertStringContainsString('upgrade before enabling scaling', $findings[0]->message);
    }

    public function test_patched_version_is_ok(): void
    {
        foreach (['v1.12.0', '1.7.0'] as $version) {
            $findings = (new ReverbIsPatched)->check($this->settings(
                server: ['scaling' => ['enabled' => true]],
                reverbVersion: $version,
            ));

            $this->assertSame(Status::Ok, $findings[0]->status, $version);
            $this->assertStringContainsString(ltrim($version, 'v'), $findings[0]->message);
        }
    }

    public function test_branch_installs_without_a_comparable_version_skip(): void
    {
        foreach (['1.x-dev', 'dev-main', 'dev-feature/foo'] as $version) {
            $findings = (new ReverbIsPatched)->check($this->settings(reverbVersion: $version));

            $this->assertCount(1, $findings, $version);
            $this->assertSame(Status::Skipped, $findings[0]->status, $version);
            $this->assertStringNotContainsString('CVE', $findings[0]->message, $version);
        }
    }

    public function test_minor_branch_alias_compares_as_newest_release_on_that_branch(): void
    {
        $patched = (new ReverbIsPatched)->check($this->settings(
            server: ['scaling' => ['enabled' => true]],
            reverbVersion: '1.7.x-dev',
        ));
        $this->assertSame(Status::Ok, $patched[0]->status);

        $affected = (new ReverbIsPatched)->check($this->settings(reverbVersion: '1.6.x-dev'));
        $this->assertSame(Status::Warning, $affected[0]->status);
        $this->assertStringContainsString('CVE-2026-23524', $affected[0]->message);

        $vulnerable = (new ReverbIsPatched)->check($this->settings(reverbVersion: '1.3.x-dev'));
        $this->assertSame(Status::Failed, $vulnerable[0]->status);
        $this->assertStringContainsString('CVE-2024-50347', $vulnerable[0]->message);
    }

    public function test_leading_v_is_stripped(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(reverbVersion: 'v1.7.0'));

        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertStringContainsString('1.7.0', $findings[0]->message);
        $this->assertStringNotContainsString('v1.7.0', $findings[0]->message);
    }

    public function test_skips_when_not_in_use(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(defaultConnection: 'log'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
    }

    public function test_skips_without_reverb_config(): void
    {
        $findings = (new ReverbIsPatched)->check($this->settings(reverbConfigLoaded: false));

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
