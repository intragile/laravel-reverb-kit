<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\ReverbKit\Console\StackCommand;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Templates\StubRenderer;
use IntrAgile\ReverbKit\Templates\TemplateValues;
use IntrAgile\ReverbKit\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class StackCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app?->make(Kernel::class);
        $this->assertInstanceOf(FoundationKernel::class, $kernel);
        $kernel->registerCommand(new StackCommand);
    }

    public function test_swarm_two_replicas_enable_scaling_and_start_first(): void
    {
        config([
            'app.name' => 'Order Desk',
            'reverb.servers.reverb.scaling' => ['enabled' => false, 'channel' => 'reverb'],
        ]);

        [$code, $output] = $this->runCommand('reverb:stack', ['--replicas' => '2', '--image' => 'app:1']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('replicas: 2', $output);
        $this->assertStringContainsString('REVERB_SCALING_ENABLED=true', $output);
        $this->assertStringContainsString('REVERB_SCALING_CHANNEL=reverb_order_desk_reverb', $output);
        $this->assertSame(2, substr_count($output, 'order: start-first'));
        $this->assertStringNotContainsString('order: stop-first', $output);
        $this->assertStringContainsString('scaling channel', $output);
    }

    public function test_explicit_scaling_channel_is_kept_without_warning(): void
    {
        [$code, $output] = $this->runCommand('reverb:stack', [
            '--replicas' => '3',
            '--image' => 'app:1',
            '--service' => 'ws',
            '--scaling-channel' => 'shop_ws',
        ]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('  ws:', $output);
        $this->assertStringContainsString('REVERB_SCALING_CHANNEL=shop_ws', $output);
        $this->assertStringNotContainsString('scaling channel', $output);
    }

    public function test_single_replica_uses_stop_first(): void
    {
        config(['reverb.servers.reverb.scaling' => ['enabled' => false, 'channel' => 'reverb']]);

        [$code, $output] = $this->runCommand('reverb:stack', ['--image' => 'app:1']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('replicas: 1', $output);
        $this->assertStringContainsString('REVERB_SCALING_ENABLED=false', $output);
        $this->assertStringContainsString('REVERB_SCALING_CHANNEL=reverb', $output);
        $this->assertSame(2, substr_count($output, 'order: stop-first'));
        $this->assertStringContainsString('command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]', $output);
        $this->assertStringContainsString('start_period: 30s', $output);
        $this->assertStringContainsString('cpus: "1.0"', $output);
        $this->assertStringContainsString('memory: 256M', $output);
        $this->assertStringContainsString('cpus: "0.05"', $output);
        $this->assertStringContainsString('memory: 80M', $output);
        $this->assertStringContainsString('- internal', $output);
        $this->assertDoesNotMatchRegularExpression('/^\s*ports:/m', $output);
        $this->assertStringNotContainsString('$', $output);
        $this->assertStringNotContainsString('{{', $output);
    }

    public function test_compose_has_no_update_config(): void
    {
        [$code, $output] = $this->runCommand('reverb:stack', ['format' => 'compose', '--image' => 'app:1']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('reverb:start', $output);
        $this->assertStringContainsString('start_period: 30s', $output);
        $this->assertStringNotContainsString('update_config', $output);
        $this->assertStringNotContainsString('rollback_config', $output);
        $this->assertDoesNotMatchRegularExpression('/^\s*ports:/m', $output);
    }

    public function test_installed_reverb_uses_up_healthcheck(): void
    {
        $version = ReverbSettings::fromConfig(config())->reverbVersion;

        if (! TemplateValues::supportsHealthEndpoint($version)) {
            $this->markTestSkipped('The installed laravel/reverb has no /up endpoint.');
        }

        [, $output] = $this->runCommand('reverb:stack', ['--image' => 'app:1']);

        $this->assertStringContainsString('test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:8080/up"]', $output);
    }

    public function test_healthcheck_falls_back_without_up_endpoint(): void
    {
        foreach ([null, '1.0.0-beta10', 'dev-main'] as $version) {
            $values = TemplateValues::from($this->settings($version), config());

            $this->assertStringContainsString('200|404|426', $values['healthcheck']);
            $this->assertStringNotContainsString('/up', $values['healthcheck']);
            $this->assertStringNotContainsString('"', $values['healthcheck']);

            $swarm = (new StubRenderer)->render('stack/swarm.yml.stub', $values);

            $this->assertStringContainsString('test: ["CMD-SHELL", "'.$values['healthcheck'].'"]', $swarm);
            $this->assertStringNotContainsString('$', $swarm);
        }

        foreach (['1.0.0-beta11', 'v1.12.0'] as $version) {
            $values = TemplateValues::from($this->settings($version), config());

            $this->assertSame('curl -fsS http://127.0.0.1:8080/up', $values['healthcheck']);
        }
    }

    public function test_invalid_replicas_fail(): void
    {
        [$code] = $this->runCommand('reverb:stack', ['--replicas' => '0']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function test_unknown_format_fails(): void
    {
        [$code, $output] = $this->runCommand('reverb:stack', ['format' => 'kubernetes']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('swarm, compose', $output);
    }

    private function settings(?string $reverbVersion): ReverbSettings
    {
        return new ReverbSettings(
            environment: 'production',
            production: true,
            defaultConnection: 'reverb',
            connectionName: 'reverb',
            connection: null,
            reverbConfigLoaded: true,
            server: ['port' => 8080, 'path' => ''],
            apps: [],
            replicas: 1,
            sharedRedis: false,
            frontendEnv: null,
            frontendEnvSource: null,
            reverbVersion: $reverbVersion,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{int, string}
     */
    private function runCommand(string $command, array $parameters = []): array
    {
        $code = Artisan::call($command, $parameters);

        return [$code, Artisan::output()];
    }
}
