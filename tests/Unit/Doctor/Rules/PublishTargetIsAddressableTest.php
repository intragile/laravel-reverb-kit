<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rules\PublishTargetIsAddressable;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishTargetIsAddressableTest extends TestCase
{
    public function test_not_in_use_skips(): void
    {
        $findings = (new PublishTargetIsAddressable)->check($this->settings(['host' => '0.0.0.0'], default: 'log'));

        $this->assertSingle(Status::Skipped, $findings);
    }

    public function test_internal_service_name_is_ok(): void
    {
        $findings = (new PublishTargetIsAddressable)->check($this->settings(['host' => 'reverb', 'port' => 8085, 'scheme' => 'http']));

        $finding = $this->assertSingle(Status::Ok, $findings);
        $this->assertStringContainsString('reverb:8085', $finding->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bindAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'ipv4 any' => ['0.0.0.0'];
        yield 'ipv6 any' => ['::'];
    }

    #[DataProvider('bindAddresses')]
    public function test_bind_address_fails(string $host): void
    {
        $findings = (new PublishTargetIsAddressable)->check($this->settings(['host' => $host]));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertSame(
            'REVERB_HOST is where Laravel sends broadcasts; the bind address belongs in REVERB_SERVER_HOST.',
            $finding->hint,
        );
    }

    public function test_missing_host_fails(): void
    {
        $findings = (new PublishTargetIsAddressable)->check($this->settings(['host' => null]));

        $this->assertSingle(Status::Failed, $findings);
    }

    public function test_loopback_warns_in_production_only(): void
    {
        $rule = new PublishTargetIsAddressable;

        $this->assertSingle(Status::Warning, $rule->check($this->settings(['host' => '127.0.0.1'], production: true)));
        $this->assertSingle(Status::Warning, $rule->check($this->settings(['host' => 'localhost'], production: true)));
        $this->assertSingle(Status::Ok, $rule->check($this->settings(['host' => '127.0.0.1'], production: false)));
        $this->assertSingle(Status::Ok, $rule->check($this->settings(['host' => 'localhost'], production: false)));
    }

    public function test_public_hostname_is_ok(): void
    {
        $findings = (new PublishTargetIsAddressable)->check($this->settings(['host' => 'ws.example.com', 'port' => 443]));

        $finding = $this->assertSingle(Status::Ok, $findings);
        $this->assertStringContainsString('ws.example.com:443', $finding->message);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function assertSingle(Status $status, array $findings): Finding
    {
        $this->assertCount(1, $findings);
        $this->assertSame($status, $findings[0]->status, $findings[0]->message);
        $this->assertSame('publish-target', $findings[0]->rule);
        $this->assertSame('publish-target', (new PublishTargetIsAddressable)->name());

        return $findings[0];
    }

    /**
     * @param  array<string, mixed>  $optionOverrides
     */
    private function settings(array $optionOverrides = [], bool $production = true, string $default = 'reverb'): ReverbSettings
    {
        return new ReverbSettings(
            environment: $production ? 'production' : 'local',
            production: $production,
            defaultConnection: $default,
            connectionName: 'reverb',
            connection: [
                'driver' => 'reverb',
                'key' => 'app-key',
                'secret' => 'app-secret',
                'app_id' => '123',
                'options' => array_replace(['host' => 'reverb', 'port' => 8085, 'scheme' => 'http', 'useTLS' => false], $optionOverrides),
            ],
            reverbConfigLoaded: true,
            server: ['host' => '0.0.0.0', 'port' => 8080, 'path' => ''],
            apps: [['key' => 'app-key', 'secret' => 'app-secret', 'app_id' => '123']],
            replicas: 1,
            sharedRedis: false,
            frontendEnv: null,
            frontendEnvSource: null,
            reverbVersion: null,
        );
    }
}
