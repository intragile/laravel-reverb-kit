<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Settings;

use IntrAgile\ReverbKit\Settings\FrontendEnv;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Tests\TestCase;

final class ReverbSettingsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/reverb-kit-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
            unlink($this->dir.'/'.$file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_from_config_reads_framework_default_reverb_connection(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options.host' => 'reverb',
            'broadcasting.connections.reverb.options.port' => '8085',
        ]);

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/missing.env');

        $this->assertTrue($settings->inUse());
        $this->assertSame('reverb', $settings->connectionName);
        $this->assertSame('reverb', $settings->connection['driver'] ?? null);
        $this->assertSame('reverb', $settings->connectionOption('host'));
        $this->assertSame('8085', $settings->connectionOption('port'));
        $this->assertNull($settings->connectionOption('missing'));
    }

    public function test_reads_reverb_server_and_apps(): void
    {
        if (! class_exists('Laravel\Reverb\ReverbServiceProvider')) {
            $this->markTestSkipped('laravel/reverb is not installed.');
        }

        config(['reverb.servers.reverb.scaling.channel' => 'reverb_core_main']);

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/missing.env', replicas: 3);

        $this->assertTrue($settings->reverbConfigLoaded);
        $this->assertSame('reverb_core_main', data_get($settings->server, 'scaling.channel'));
        $this->assertCount(1, $settings->apps);
        $this->assertSame(3, $settings->replicas);
        $this->assertNotNull($settings->reverbVersion);
    }

    public function test_from_config_without_reverb_config(): void
    {
        config(['reverb' => null]);

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/missing.env');

        $this->assertFalse($settings->reverbConfigLoaded);
        $this->assertNull($settings->server);
        $this->assertSame([], $settings->apps);
    }

    public function test_in_use_false_for_null_default(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->assertFalse(ReverbSettings::fromConfig(config(), $this->dir.'/missing.env')->inUse());
    }

    public function test_in_use_when_default_connection_uses_reverb_driver(): void
    {
        config([
            'reverb-kit.connection' => 'realtime',
            'broadcasting.default' => 'realtime',
            'broadcasting.connections.realtime' => ['driver' => 'reverb', 'key' => 'k'],
        ]);

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/missing.env');

        $this->assertTrue($settings->inUse());
        $this->assertSame('k', $settings->connection['key'] ?? null);
    }

    public function test_production_follows_configured_environments(): void
    {
        config(['app.env' => 'live', 'reverb-kit.production_environments' => ['live']]);

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/missing.env');

        $this->assertSame('live', $settings->environment);
        $this->assertTrue($settings->production);
    }

    public function test_frontend_env_keeps_only_vite_reverb_keys(): void
    {
        file_put_contents($this->dir.'/.env', implode("\n", [
            'APP_KEY=base64:secret',
            'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST=ws.example.com',
            "VITE_REVERB_PORT='443'",
            '# VITE_REVERB_SCHEME=http',
            'VITE_APP_NAME=x',
        ]));

        $this->assertSame([
            'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
            'VITE_REVERB_HOST' => 'ws.example.com',
            'VITE_REVERB_PORT' => '443',
        ], FrontendEnv::read($this->dir.'/.env'));

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/.env');

        $this->assertSame('ws.example.com', $settings->frontendEnv['VITE_REVERB_HOST'] ?? null);
        $this->assertSame($this->dir.'/.env', $settings->frontendEnvSource);
    }

    public function test_frontend_env_missing_file_is_null(): void
    {
        $this->assertNull(FrontendEnv::read($this->dir.'/missing.env'));

        file_put_contents($this->dir.'/plain.env', "APP_NAME=x\n");
        $this->assertNull(FrontendEnv::read($this->dir.'/plain.env'));

        $settings = ReverbSettings::fromConfig(config(), $this->dir.'/missing.env');
        $this->assertNull($settings->frontendEnv);
        $this->assertSame($this->dir.'/missing.env', $settings->frontendEnvSource);
    }

    public function test_frontend_env_path_falls_back_to_config_then_base_env(): void
    {
        file_put_contents($this->dir.'/front.env', "VITE_REVERB_HOST=ws.example.com\n");
        config(['reverb-kit.frontend_env' => $this->dir.'/front.env']);

        $this->assertSame($this->dir.'/front.env', ReverbSettings::fromConfig(config())->frontendEnvSource);

        config(['reverb-kit.frontend_env' => null]);
        $this->assertSame(base_path('.env'), ReverbSettings::fromConfig(config())->frontendEnvSource);
    }
}
