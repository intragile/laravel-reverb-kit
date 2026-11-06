<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rules\FrontendEnvMatches;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\FrontendEnv;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class FrontendEnvMatchesTest extends TestCase
{
    public function test_name_is_frontend_env(): void
    {
        $this->assertSame('frontend-env', (new FrontendEnvMatches)->name());
    }

    public function test_not_in_use_skips(): void
    {
        $findings = $this->check($this->settings($this->fixture('public.env'), inUse: false));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
        $this->assertStringContainsString('not the default broadcaster', $findings[0]->message);
    }

    public function test_no_file_skips_with_source_in_message(): void
    {
        $findings = $this->check($this->settings(null, source: '/nonexistent/.env'));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
        $this->assertStringContainsString('/nonexistent/.env', $findings[0]->message);
    }

    public function test_no_file_without_source_names_the_frontend_env_file(): void
    {
        $findings = $this->check($this->settings(null, source: null));

        $this->assertCount(1, $findings);
        $this->assertSame(Status::Skipped, $findings[0]->status);
        $this->assertStringContainsString('the frontend env file', $findings[0]->message);
    }

    public function test_public_profile_is_ok(): void
    {
        $findings = $this->check($this->settings($this->fixture('public.env'), production: true));

        $this->assertNoStatus(Status::Failed, $findings);
        $this->assertNoStatus(Status::Warning, $findings);
        $this->assertCount(1, $findings);
        $this->assertSame(Status::Ok, $findings[0]->status);
        $this->assertStringContainsString('reverb connection', $findings[0]->message);
    }

    public function test_internal_name_in_vite_host_fails_in_production(): void
    {
        $findings = $this->check($this->settings($this->fixture('internal-host.env'), production: true));

        $failed = $this->withStatus(Status::Failed, $findings);
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('reverb', $failed[0]->message);
        $this->assertStringContainsString('browsers cannot resolve it', $failed[0]->message);
        $this->assertNotNull($failed[0]->hint);
    }

    public function test_internal_name_in_vite_host_is_ok_outside_production(): void
    {
        $findings = $this->check($this->settings($this->fixture('internal-host.env'), production: false));

        $this->assertNoStatus(Status::Failed, $findings);
    }

    public function test_loopback_host_fails_in_production(): void
    {
        $findings = $this->check($this->settings($this->values(['VITE_REVERB_HOST' => '127.0.0.1']), production: true));

        $this->assertCount(1, $this->withStatus(Status::Failed, $findings));
    }

    public function test_loopback_host_is_ok_outside_production(): void
    {
        $findings = $this->check($this->settings($this->values(['VITE_REVERB_HOST' => 'localhost']), production: false));

        $this->assertNoStatus(Status::Failed, $findings);
        $this->assertNoStatus(Status::Warning, $findings);
    }

    public function test_bind_address_host_fails(): void
    {
        foreach (['0.0.0.0', '::', ''] as $host) {
            $findings = $this->check($this->settings($this->values(['VITE_REVERB_HOST' => $host]), production: false));

            $this->assertCount(1, $this->withStatus(Status::Failed, $findings), "host '{$host}'");
        }
    }

    public function test_missing_host_fails(): void
    {
        $values = $this->values();
        unset($values['VITE_REVERB_HOST']);

        $findings = $this->check($this->settings($values));

        $this->assertCount(1, $this->withStatus(Status::Failed, $findings));
    }

    public function test_key_mismatch_fails(): void
    {
        $findings = $this->check($this->settings($this->fixture('key-mismatch.env'), production: true));

        $failed = $this->withStatus(Status::Failed, $findings);
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY', $failed[0]->message);
        $this->assertStringContainsString('${REVERB_APP_KEY}', (string) $failed[0]->hint);
    }

    public function test_missing_key_fails(): void
    {
        $values = $this->values();
        unset($values['VITE_REVERB_APP_KEY']);

        $findings = $this->check($this->settings($values));

        $this->assertCount(1, $this->withStatus(Status::Failed, $findings));
    }

    public function test_key_is_not_compared_when_connection_has_no_key(): void
    {
        $findings = $this->check($this->settings($this->fixture('key-mismatch.env'), connectionKey: ''));

        $this->assertNoStatus(Status::Failed, $findings);
    }

    public function test_interpolated_values_are_not_judged(): void
    {
        $findings = $this->check($this->settings($this->fixture('interpolated.env'), production: true));

        $this->assertNoStatus(Status::Failed, $findings);
        $this->assertNoStatus(Status::Warning, $findings);

        $ok = $this->withStatus(Status::Ok, $findings);
        $this->assertCount(1, $ok);
        $this->assertStringContainsString('interpolated, checked at build time', $ok[0]->message);
        $this->assertStringContainsString('VITE_REVERB_HOST', $ok[0]->message);
    }

    public function test_partly_interpolated_values_are_not_judged(): void
    {
        $findings = $this->check($this->settings($this->values(['VITE_REVERB_HOST' => 'ws-${APP_ENV}']), production: true));

        $this->assertNoStatus(Status::Failed, $findings);
    }

    public function test_bare_dollar_references_are_not_judged(): void
    {
        $findings = $this->check($this->settings($this->values([
            'VITE_REVERB_APP_KEY' => '$REVERB_APP_KEY',
            'VITE_REVERB_HOST' => '$REVERB_HOST',
        ]), production: true));

        $this->assertNoStatus(Status::Failed, $findings);

        $ok = $this->withStatus(Status::Ok, $findings);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY', $ok[0]->message);
        $this->assertStringContainsString('VITE_REVERB_HOST', $ok[0]->message);
    }

    public function test_escaped_dollar_is_judged_as_a_literal(): void
    {
        $findings = $this->check($this->settings($this->values(['VITE_REVERB_APP_KEY' => '\$REVERB_APP_KEY'])));

        $failed = $this->withStatus(Status::Failed, $findings);
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('does not match', $failed[0]->message);
    }

    public function test_inconsistent_scheme_and_port_warns(): void
    {
        $cases = [
            ['443', 'http'],
            ['80', 'https'],
        ];

        foreach ($cases as [$port, $scheme]) {
            $findings = $this->check($this->settings($this->values([
                'VITE_REVERB_PORT' => $port,
                'VITE_REVERB_SCHEME' => $scheme,
            ])));

            $this->assertCount(1, $this->withStatus(Status::Warning, $findings), "{$scheme}:{$port}");
            $this->assertNoStatus(Status::Failed, $findings);
        }
    }

    public function test_https_on_a_custom_port_does_not_warn(): void
    {
        $findings = $this->check($this->settings($this->values(['VITE_REVERB_PORT' => '8443'])));

        $this->assertNoStatus(Status::Warning, $findings);
    }

    public function test_missing_scheme_and_port_do_not_warn(): void
    {
        $values = $this->values();
        unset($values['VITE_REVERB_PORT'], $values['VITE_REVERB_SCHEME']);

        $findings = $this->check($this->settings($values));

        $this->assertNoStatus(Status::Warning, $findings);
        $this->assertNoStatus(Status::Failed, $findings);
    }

    /**
     * @return list<Finding>
     */
    private function check(ReverbSettings $settings): array
    {
        return (new FrontendEnvMatches)->check($settings);
    }

    /**
     * @return array<string, string>
     */
    private function fixture(string $name): array
    {
        $values = FrontendEnv::read(__DIR__.'/../../../Fixtures/frontend/'.$name);

        $this->assertNotNull($values, "fixture {$name}");

        return $values;
    }

    /**
     * The public profile with some values replaced.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return array_merge($this->fixture('public.env'), $overrides);
    }

    /**
     * @param  array<string, string>|null  $frontendEnv
     */
    private function settings(
        ?array $frontendEnv,
        bool $production = false,
        ?string $source = '/app/.env',
        bool $inUse = true,
        string $connectionKey = 'app-key',
    ): ReverbSettings {
        return new ReverbSettings(
            environment: $production ? 'production' : 'local',
            production: $production,
            defaultConnection: $inUse ? 'reverb' : 'log',
            connectionName: 'reverb',
            connection: [
                'driver' => 'reverb',
                'key' => $connectionKey,
                'options' => ['host' => 'reverb', 'port' => 8085, 'scheme' => 'http'],
            ],
            reverbConfigLoaded: true,
            server: null,
            apps: [],
            replicas: 1,
            sharedRedis: false,
            frontendEnv: $frontendEnv,
            frontendEnvSource: $source,
            reverbVersion: null,
        );
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function withStatus(Status $status, array $findings): array
    {
        return array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->status === $status));
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function assertNoStatus(Status $status, array $findings): void
    {
        $this->assertSame([], $this->withStatus($status, $findings), "unexpected {$status->value} finding");
    }
}
