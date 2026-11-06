<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rules\SchemeMatchesPort;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class SchemeMatchesPortTest extends TestCase
{
    public function test_not_in_use_skips(): void
    {
        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'https', 'useTLS' => false], default: null));

        $this->assertSingle(Status::Skipped, $findings);
    }

    public function test_https_without_tls_fails(): void
    {
        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'https', 'port' => 443, 'useTLS' => false]));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertNotNull($finding->hint);
    }

    public function test_http_with_tls_fails(): void
    {
        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'http', 'port' => 8080, 'useTLS' => true]));

        $this->assertSingle(Status::Failed, $findings);
    }

    public function test_443_with_http_warns(): void
    {
        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'http', 'port' => 443, 'useTLS' => false]));

        $this->assertSingle(Status::Warning, $findings);

        // Ports read from env() arrive as strings.
        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'http', 'port' => '443', 'useTLS' => false]));

        $this->assertSingle(Status::Warning, $findings);
    }

    public function test_80_with_https_warns(): void
    {
        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'https', 'port' => 80, 'useTLS' => true]));

        $this->assertSingle(Status::Warning, $findings);
    }

    public function test_consistent_settings_are_ok(): void
    {
        $rule = new SchemeMatchesPort;

        $this->assertSingle(Status::Ok, $rule->check($this->settings(['scheme' => 'http', 'port' => 8085, 'useTLS' => false])));
        $this->assertSingle(Status::Ok, $rule->check($this->settings(['scheme' => 'https', 'port' => 443, 'useTLS' => true])));
        $this->assertSingle(Status::Ok, $rule->check($this->settings(['scheme' => 'https', 'port' => '443', 'useTLS' => null])));
    }

    public function test_app_option_mismatch_only_warns(): void
    {
        $apps = [
            ['app_id' => 'good', 'key' => 'k1', 'options' => ['host' => 'ws.example.com', 'port' => 443, 'scheme' => 'https', 'useTLS' => true]],
            ['app_id' => 'tls-mismatch', 'key' => 'k2', 'options' => ['host' => 'ws.example.com', 'port' => 443, 'scheme' => 'https', 'useTLS' => false]],
            ['app_id' => 'port-mismatch', 'key' => 'k3', 'options' => ['host' => 'ws.example.com', 'port' => 443, 'scheme' => 'http']],
        ];

        $findings = (new SchemeMatchesPort)->check($this->settings(['scheme' => 'http', 'port' => 8085, 'useTLS' => false], $apps));

        // The connection finding comes first, then one per app with options.
        $this->assertCount(4, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertStringContainsString('connection', $findings[0]->message);
        $this->assertSame(Status::Ok, $findings[1]->status);
        $this->assertStringContainsString('good', $findings[1]->message);
        $this->assertSame(Status::Warning, $findings[2]->status);
        $this->assertStringContainsString('tls-mismatch', $findings[2]->message);
        $this->assertSame(Status::Warning, $findings[3]->status);
        $this->assertStringContainsString('port-mismatch', $findings[3]->message);

        foreach ($findings as $finding) {
            $this->assertSame('scheme-port', $finding->rule);
        }
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function assertSingle(Status $status, array $findings): Finding
    {
        $this->assertCount(1, $findings);
        $this->assertSame($status, $findings[0]->status, $findings[0]->message);
        $this->assertSame('scheme-port', $findings[0]->rule);
        $this->assertSame('scheme-port', (new SchemeMatchesPort)->name());

        return $findings[0];
    }

    /**
     * @param  array<string, mixed>  $optionOverrides
     * @param  list<array<string, mixed>>  $apps
     */
    private function settings(array $optionOverrides = [], array $apps = [], ?string $default = 'reverb'): ReverbSettings
    {
        return new ReverbSettings(
            environment: 'production',
            production: true,
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
            apps: $apps,
            replicas: 1,
            sharedRedis: false,
            frontendEnv: null,
            frontendEnvSource: null,
            reverbVersion: null,
        );
    }
}
