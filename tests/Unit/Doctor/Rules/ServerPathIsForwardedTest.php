<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor\Rules;

use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rules\ServerPathIsForwarded;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use PHPUnit\Framework\TestCase;

final class ServerPathIsForwardedTest extends TestCase
{
    private const HINT = "Laravel 13's default reverb connection has no path option; add 'path' => '/ws/' to the connection. The Pusher SDK resolves the publish URL against this path, so without the leading slash the URL is malformed and without the trailing slash the prefix is dropped.";

    public function test_not_in_use_skips(): void
    {
        $findings = (new ServerPathIsForwarded)->check($this->settings('ws', null, default: 'pusher'));

        $this->assertSingle(Status::Skipped, $findings);
    }

    public function test_without_reverb_config_skips(): void
    {
        $findings = (new ServerPathIsForwarded)->check($this->settings('ws', null, reverbConfigLoaded: false));

        $this->assertSingle(Status::Skipped, $findings);
    }

    public function test_empty_server_path_is_ok(): void
    {
        $rule = new ServerPathIsForwarded;

        $this->assertSingle(Status::Ok, $rule->check($this->settings('', null)));
        $this->assertSingle(Status::Ok, $rule->check($this->settings(null, null)));
    }

    public function test_path_missing_from_connection_fails(): void
    {
        $findings = (new ServerPathIsForwarded)->check($this->settings('ws', null));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertSame(self::HINT, $finding->hint);
    }

    public function test_path_mismatch_fails(): void
    {
        $findings = (new ServerPathIsForwarded)->check($this->settings('ws', 'socket'));

        $finding = $this->assertSingle(Status::Failed, $findings);
        $this->assertStringContainsString('ws', $finding->message);
        $this->assertStringContainsString('socket', $finding->message);
    }

    public function test_matching_path_is_ok(): void
    {
        $rule = new ServerPathIsForwarded;

        $this->assertSingle(Status::Ok, $rule->check($this->settings('/ws', '/ws/')));
        $this->assertSingle(Status::Ok, $rule->check($this->settings('ws', '/ws/')));
        $this->assertSingle(Status::Ok, $rule->check($this->settings('ws/', '/ws/')));
    }

    /**
     * The Pusher SDK drops the prefix for '/ws' and builds a malformed URL for 'ws' and 'ws/'.
     */
    public function test_path_without_both_slashes_fails(): void
    {
        $rule = new ServerPathIsForwarded;

        foreach (['/ws', 'ws', 'ws/'] as $connectionPath) {
            $finding = $this->assertSingle(Status::Failed, $rule->check($this->settings('/ws', $connectionPath)));
            $this->assertSame(self::HINT, $finding->hint);
            $this->assertStringContainsString("'/ws/'", $finding->message);
        }
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function assertSingle(Status $status, array $findings): Finding
    {
        $this->assertCount(1, $findings);
        $this->assertSame($status, $findings[0]->status, $findings[0]->message);
        $this->assertSame('server-path', $findings[0]->rule);
        $this->assertSame('server-path', (new ServerPathIsForwarded)->name());

        return $findings[0];
    }

    private function settings(
        ?string $serverPath,
        ?string $connectionPath,
        bool $reverbConfigLoaded = true,
        string $default = 'reverb',
    ): ReverbSettings {
        $options = ['host' => 'reverb', 'port' => 8085, 'scheme' => 'http', 'useTLS' => false];

        if ($connectionPath !== null) {
            $options['path'] = $connectionPath;
        }

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
                'options' => $options,
            ],
            reverbConfigLoaded: $reverbConfigLoaded,
            server: $reverbConfigLoaded ? ['host' => '0.0.0.0', 'port' => 8080, 'path' => $serverPath] : null,
            apps: [],
            replicas: 1,
            sharedRedis: false,
            frontendEnv: null,
            frontendEnvSource: null,
            reverbVersion: null,
        );
    }
}
