<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rules\ConnectionMatchesApp;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionMatchesAppTest extends TestCase
{
    public function test_not_in_use_skips(): void
    {
        $findings = $this->rule()->check($this->settings(default: 'pusher'));

        $this->assertSingle(Status::Skipped, $findings);
    }

    public function test_key_without_matching_app_fails(): void
    {
        $findings = $this->rule()->check($this->settings(['key' => 'other-key']));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertStringContainsString('other-key', $finding->message);
        $this->assertNotNull($finding->hint);
    }

    public function test_secret_mismatch_fails(): void
    {
        $findings = $this->rule()->check($this->settings(['secret' => 'wrong-secret']));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertStringContainsString('secret', $finding->message);
        $this->assertStringNotContainsString('wrong-secret', $finding->message);
    }

    public function test_app_id_mismatch_fails(): void
    {
        $findings = $this->rule()->check($this->settings(['app_id' => '999']));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertStringContainsString('app_id', $finding->message);
    }

    /**
     * @return iterable<string, array{int|string, int|string}>
     */
    public static function matchingAppIds(): iterable
    {
        yield 'string app_id on both sides' => ['123', '123'];
        yield 'int app_id in reverb config' => ['123', 123];
        yield 'int app_id in connection' => [123, '123'];
    }

    #[DataProvider('matchingAppIds')]
    public function test_matching_credentials_are_ok(int|string $connectionAppId, int|string $appId): void
    {
        $settings = $this->settings(
            ['app_id' => $connectionAppId],
            apps: [['key' => 'app-key', 'secret' => 'app-secret', 'app_id' => $appId]],
        );

        $this->assertSingle(Status::Ok, $this->rule()->check($settings));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialFields(): iterable
    {
        yield 'key' => ['key'];
        yield 'secret' => ['secret'];
        yield 'app_id' => ['app_id'];
    }

    #[DataProvider('credentialFields')]
    public function test_empty_credentials_fail(string $field): void
    {
        foreach ([true, false] as $reverbConfigLoaded) {
            $findings = $this->rule()->check($this->settings([$field => ''], reverbConfigLoaded: $reverbConfigLoaded));

            $finding = $this->assertSingle(Status::Failed, $findings);
            $this->assertStringContainsString($field, $finding->message);
        }

        $findings = $this->rule()->check($this->settings([$field => null]));
        $this->assertSingle(Status::Failed, $findings);
    }

    public function test_without_reverb_config_only_checks_presence(): void
    {
        $settings = $this->settings(['key' => 'unknown-key', 'secret' => 'unknown-secret'], reverbConfigLoaded: false);

        $this->assertSingle(Status::Ok, $this->rule()->check($settings));
    }

    public function test_covered_by_upstream_diagnostic_skips(): void
    {
        $findings = (new ConnectionMatchesApp(\stdClass::class, \stdClass::class))->check($this->settings(['key' => 'other-key']));

        $finding = $this->assertSingle(Status::Skipped, $findings);
        $this->assertSame('covered by laravel/reverb', $finding->message);
    }

    public function test_upstream_diagnostic_without_laravel_doctor_is_not_covered(): void
    {
        $rule = new ConnectionMatchesApp(\stdClass::class, 'IntrAgile\ReverbKit\Tests\Missing\Diagnostic');

        $this->assertSingle(Status::Failed, $rule->check($this->settings(['key' => 'other-key'])));
    }

    public function test_upstream_diagnostic_with_missing_parent_does_not_throw(): void
    {
        $class = 'IntrAgile\ReverbKit\Tests\Fixtures\BrokenUpstreamDiagnostic';
        $loader = static function (string $name) use ($class): void {
            if ($name === $class) {
                eval('namespace IntrAgile\ReverbKit\Tests\Fixtures; final class BrokenUpstreamDiagnostic extends \IntrAgile\ReverbKit\Tests\Missing\Parent_ {}');
            }
        };

        spl_autoload_register($loader);

        try {
            $rule = new ConnectionMatchesApp($class, \stdClass::class);

            $this->assertSingle(Status::Failed, $rule->check($this->settings(['key' => 'other-key'])));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function test_upstream_base_constant_is_pinned(): void
    {
        $this->assertSame('Laravel\Doctor\Diagnostic', ConnectionMatchesApp::UPSTREAM_DIAGNOSTIC_BASE);
    }

    public function test_upstream_constant_is_pinned(): void
    {
        $this->assertSame(
            'Laravel\Reverb\Diagnostics\ReverbBroadcasterCredentialsMatchApp',
            ConnectionMatchesApp::UPSTREAM_DIAGNOSTIC,
        );
    }

    private function rule(): ConnectionMatchesApp
    {
        // An upstream class that cannot exist keeps the own check active regardless of the installed reverb version.
        return new ConnectionMatchesApp(null);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function assertSingle(Status $status, array $findings): Finding
    {
        $this->assertCount(1, $findings);
        $this->assertSame($status, $findings[0]->status, $findings[0]->message);
        $this->assertSame('connection-credentials', $findings[0]->rule);
        $this->assertSame('connection-credentials', $this->rule()->name());

        return $findings[0];
    }

    /**
     * @param  array<string, mixed>  $connectionOverrides
     * @param  list<array<string, mixed>>|null  $apps
     */
    private function settings(
        array $connectionOverrides = [],
        ?array $apps = null,
        bool $reverbConfigLoaded = true,
        string $default = 'reverb',
    ): ReverbSettings {
        $connection = array_replace([
            'driver' => 'reverb',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => '123',
            'options' => ['host' => 'reverb', 'port' => 8085, 'scheme' => 'http', 'useTLS' => false],
        ], $connectionOverrides);

        return new ReverbSettings(
            environment: 'production',
            production: true,
            defaultConnection: $default,
            connectionName: 'reverb',
            connection: $connection,
            reverbConfigLoaded: $reverbConfigLoaded,
            server: $reverbConfigLoaded ? ['host' => '0.0.0.0', 'port' => 8080, 'path' => ''] : null,
            apps: $reverbConfigLoaded ? ($apps ?? [['key' => 'app-key', 'secret' => 'app-secret', 'app_id' => '123']]) : [],
            replicas: 1,
            sharedRedis: false,
            frontendEnv: null,
            frontendEnvSource: null,
            reverbVersion: null,
        );
    }
}
