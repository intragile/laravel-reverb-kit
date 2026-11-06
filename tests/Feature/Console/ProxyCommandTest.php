<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\ReverbKit\Console\ProxyCommand;
use IntrAgile\ReverbKit\Console\StackCommand;
use IntrAgile\ReverbKit\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class ProxyCommandTest extends TestCase
{
    private ?string $outputDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app?->make(Kernel::class);
        $this->assertInstanceOf(FoundationKernel::class, $kernel);
        $kernel->registerCommand(new ProxyCommand);
        $kernel->registerCommand(new StackCommand);
    }

    protected function tearDown(): void
    {
        if ($this->outputDir !== null) {
            foreach (array_diff(scandir($this->outputDir) ?: [], ['.', '..']) as $file) {
                unlink($this->outputDir.'/'.$file);
            }

            rmdir($this->outputDir);
            $parent = dirname($this->outputDir);

            if (is_dir($parent) && array_diff(scandir($parent) ?: [], ['.', '..']) === []) {
                rmdir($parent);
            }
        }

        parent::tearDown();
    }

    public function test_nginx_uses_variable_proxy_pass_and_resolver(): void
    {
        [$code, $output] = $this->runCommand('reverb:proxy');

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('location /app/ {', $output);
        $this->assertStringContainsString('resolver 127.0.0.11 valid=10s;', $output);
        $this->assertStringContainsString('set $reverb_upstream "reverb:8080";', $output);
        $this->assertStringContainsString('proxy_pass http://$reverb_upstream;', $output);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $output);
        $this->assertStringContainsString('proxy_read_timeout 120s;', $output);
        $this->assertStringContainsString('proxy_send_timeout 120s;', $output);
        $this->assertStringNotContainsString('proxy_pass http://reverb', $output);
        $this->assertStringNotContainsString('location /apps', $output);
        $this->assertSame(1, substr_count($output, 'proxy_pass http://$reverb_upstream;'));
        $this->assertStringNotContainsString('{{', $output);
    }

    public function test_upstream_and_ping_interval_are_taken_into_account(): void
    {
        config(['reverb.apps.apps' => [['key' => 'k', 'ping_interval' => 90]]]);

        [$code, $output] = $this->runCommand('reverb:proxy', ['--upstream' => 'ws-internal']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('set $reverb_upstream "ws-internal:8080";', $output);
        $this->assertStringContainsString('proxy_read_timeout 180s;', $output);
    }

    public function test_nginx_with_api_adds_apps_location(): void
    {
        [$code, $output] = $this->runCommand('reverb:proxy', ['--with-api' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('location /app/ {', $output);
        $this->assertStringContainsString('location /apps/ {', $output);
        $this->assertStringContainsString('Only needed when the publisher runs outside', $output);
        $this->assertStringContainsString('signature', $output);
        $this->assertSame(2, substr_count($output, 'proxy_pass http://$reverb_upstream;'));
    }

    public function test_path_prefix_is_applied_everywhere(): void
    {
        config(['reverb.servers.reverb.path' => '/ws/']);

        [, $nginx] = $this->runCommand('reverb:proxy', ['server' => 'nginx', '--with-api' => true]);
        [, $caddy] = $this->runCommand('reverb:proxy', ['server' => 'caddy']);
        [, $traefik] = $this->runCommand('reverb:proxy', ['server' => 'traefik']);
        [, $swarm] = $this->runCommand('reverb:stack', ['format' => 'swarm', '--image' => 'app:1']);

        $this->assertStringContainsString('location /ws/app/ {', $nginx);
        $this->assertStringContainsString('location /ws/apps/ {', $nginx);
        $this->assertStringContainsString('handle /ws/app/* {', $caddy);
        $this->assertStringContainsString('PathPrefix(`/ws/app/`)', $traefik);
        $this->assertStringContainsString('http://127.0.0.1:8080/ws/', $swarm);
        $this->assertStringContainsString('REVERB_SERVER_PATH=/ws', $swarm);
    }

    public function test_caddy_proxies_app_prefix(): void
    {
        config(['reverb-kit.templates.public_host' => 'ws.example.com']);

        [$code, $output] = $this->runCommand('reverb:proxy', ['server' => 'caddy']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('ws.example.com {', $output);
        $this->assertStringContainsString('handle /app/* {', $output);
        $this->assertStringContainsString('reverse_proxy reverb:8080', $output);
        // caddy fmt indents with tabs; spaces make `caddy validate` warn about formatting.
        $this->assertStringContainsString("\n\thandle /app/* {\n\t\treverse_proxy reverb:8080\n\t}\n}", $output);
        $this->assertStringContainsString('dynamic a', $output);
    }

    public function test_traefik_uses_deploy_labels(): void
    {
        [$code, $output] = $this->runCommand('reverb:proxy', ['server' => 'traefik', '--public-host' => 'ws.example.com']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertMatchesRegularExpression('/^deploy:\n  labels:$/m', $output);
        $this->assertStringContainsString('Host(`ws.example.com`) && PathPrefix(`/app/`)', $output);
        $this->assertStringContainsString('loadbalancer.server.port=8080', $output);
    }

    public function test_with_api_outside_nginx_only_warns(): void
    {
        [$code, $output] = $this->runCommand('reverb:proxy', ['server' => 'caddy', '--with-api' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('--with-api only applies to nginx', $output);
        $this->assertStringNotContainsString('handle /apps', $output);
    }

    public function test_unknown_server_fails(): void
    {
        [$code, $output] = $this->runCommand('reverb:proxy', ['server' => 'apache']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('nginx, caddy, traefik', $output);
    }

    public function test_existing_output_requires_force(): void
    {
        $this->outputDir = dirname(__DIR__, 2).'/.generated/'.bin2hex(random_bytes(4));
        mkdir($this->outputDir, 0777, true);
        $target = $this->outputDir.'/reverb.conf';
        file_put_contents($target, 'keep me');

        [$code, $output] = $this->runCommand('reverb:proxy', ['--output' => $target]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('--force', $output);
        $this->assertSame('keep me', file_get_contents($target));

        [$code] = $this->runCommand('reverb:proxy', ['--output' => $target, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('proxy_pass http://$reverb_upstream;', (string) file_get_contents($target));
    }

    public function test_output_writes_new_file(): void
    {
        $this->outputDir = dirname(__DIR__, 2).'/.generated/'.bin2hex(random_bytes(4));
        mkdir($this->outputDir, 0777, true);
        $target = $this->outputDir.'/Caddyfile';

        [$code, $output] = $this->runCommand('reverb:proxy', ['server' => 'caddy', '--output' => $target]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringNotContainsString('reverse_proxy', $output);
        $this->assertStringContainsString('reverse_proxy reverb:8080', (string) file_get_contents($target));
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
