<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\ReverbKit\Console\EnvCommand;
use IntrAgile\ReverbKit\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class EnvCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app?->make(Kernel::class);
        $this->assertInstanceOf(FoundationKernel::class, $kernel);
        $kernel->registerCommand(new EnvCommand);
    }

    public function test_env_separates_bind_and_publish_hosts(): void
    {
        $code = Artisan::call('reverb:env', ['--public-host' => 'ws.example.com', '--internal-host' => 'reverb', '--port' => '8085']);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertMatchesRegularExpression('/^REVERB_SERVER_HOST=0\.0\.0\.0$/m', $output);
        $this->assertMatchesRegularExpression('/^REVERB_SERVER_PORT=8085$/m', $output);
        $this->assertMatchesRegularExpression('/^REVERB_HOST=reverb$/m', $output);
        $this->assertMatchesRegularExpression('/^REVERB_PORT=8085$/m', $output);
        $this->assertMatchesRegularExpression('/^REVERB_SCHEME=http$/m', $output);
        $this->assertMatchesRegularExpression('/^VITE_REVERB_HOST=ws\.example\.com$/m', $output);
        $this->assertMatchesRegularExpression('/^VITE_REVERB_PORT=443$/m', $output);
        $this->assertMatchesRegularExpression('/^VITE_REVERB_SCHEME=https$/m', $output);
        $this->assertStringContainsString('Bind address', $output);
        $this->assertStringNotContainsString('{{', $output);
    }

    public function test_defaults_come_from_config(): void
    {
        config(['reverb-kit.templates.public_host' => 'live.example.com', 'reverb-kit.templates.upstream_host' => 'ws']);

        $code = Artisan::call('reverb:env');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertMatchesRegularExpression('/^REVERB_HOST=ws$/m', $output);
        $this->assertMatchesRegularExpression('/^VITE_REVERB_HOST=live\.example\.com$/m', $output);
    }

    public function test_bind_address_as_internal_host_fails(): void
    {
        $code = Artisan::call('reverb:env', ['--internal-host' => '0.0.0.0']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('bind address', Artisan::output());
    }
}
