<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Templates;

use IntrAgile\ReverbKit\Templates\StubRenderer;
use IntrAgile\ReverbKit\Tests\TestCase;
use InvalidArgumentException;

final class StubRendererTest extends TestCase
{
    /** @var list<string> */
    private array $createdDirs = [];

    /** @var list<string> */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->createdDirs) as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        parent::tearDown();
    }

    public function test_published_stub_overrides_package_stub(): void
    {
        $this->writeFile(base_path('stubs/reverb-kit/proxy/nginx.conf.stub'), "# custom\nlocation {{ path }}/custom {}\n");

        $output = (new StubRenderer)->render('proxy/nginx.conf.stub', ['path' => '/ws']);

        $this->assertSame("# custom\nlocation /ws/custom {}\n", $output);
    }

    public function test_package_stub_is_used_without_published_copy(): void
    {
        $output = (new StubRenderer)->render('env/reverb.env.stub', [
            'upstream_host' => 'reverb',
            'upstream_port' => '8080',
            'path' => '',
            'public_host' => 'ws.example.com',
        ]);

        $this->assertStringContainsString('VITE_REVERB_HOST=ws.example.com', $output);
    }

    public function test_unfilled_placeholder_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('{{ upstream_host }}');

        (new StubRenderer)->fill('proxy_pass http://{{ upstream_host }}:{{ upstream_port }};', ['upstream_port' => '8080']);
    }

    public function test_unfilled_placeholder_in_stub_names_the_stub(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unfilled placeholder {{ resolver }} in stub proxy/nginx.conf.stub');

        (new StubRenderer)->render('proxy/nginx.conf.stub', [
            'path' => '',
            'public_host' => 'ws.example.com',
            'upstream_host' => 'reverb',
            'upstream_port' => '8080',
            'read_timeout' => '120',
            'api_location' => '',
        ]);
    }

    public function test_missing_stub_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does-not-exist.stub');

        (new StubRenderer)->render('does-not-exist.stub', []);
    }

    public function test_values_are_not_rescanned(): void
    {
        $output = (new StubRenderer)->fill('a={{ a }} b={{b}}', ['a' => '{{ b }}', 'b' => 'x']);

        $this->assertSame('a={{ b }} b=x', $output);
    }

    private function writeFile(string $path, string $contents): void
    {
        $missing = [];

        for ($dir = dirname($path); ! is_dir($dir); $dir = dirname($dir)) {
            $missing[] = $dir;
        }

        foreach (array_reverse($missing) as $dir) {
            mkdir($dir);
            $this->createdDirs[] = $dir;
        }

        $this->assertFileDoesNotExist($path);
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;
    }
}
