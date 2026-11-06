<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Support;

use IntrAgile\ReverbKit\Support\Hosts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostsTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, bool, bool, bool, bool}>
     */
    public static function hosts(): iterable
    {
        // host => [bind address, IP literal, loopback, public hostname]
        yield 'null' => [null, true, false, false, false];
        yield 'empty' => ['', true, false, false, false];
        yield 'blank' => ['  ', true, false, false, false];
        yield 'ipv4 any' => ['0.0.0.0', true, true, false, false];
        yield 'ipv6 any' => ['::', true, true, false, false];
        yield 'ipv6 any bracketed' => ['[::]', true, true, false, false];
        yield 'localhost' => ['localhost', false, false, true, false];
        yield 'ipv4 loopback' => ['127.0.0.1', false, true, true, false];
        yield 'ipv4 loopback range' => ['127.0.1.1', false, true, true, false];
        yield 'ipv6 loopback' => ['[::1]', false, true, true, false];
        yield 'service name' => ['reverb', false, false, false, false];
        yield 'public name' => ['ws.example.com', false, false, false, true];
        yield 'private ip' => ['10.0.0.5', false, true, false, false];
    }

    #[DataProvider('hosts')]
    public function test_classification(?string $host, bool $bind, bool $ip, bool $loopback, bool $public): void
    {
        $this->assertSame($bind, Hosts::isBindAddress($host), 'isBindAddress');
        $this->assertSame($ip, $host !== null && Hosts::isIpLiteral($host), 'isIpLiteral');
        $this->assertSame($loopback, $host !== null && Hosts::isLoopback($host), 'isLoopback');
        $this->assertSame($public, $host !== null && Hosts::isPublicHostname($host), 'isPublicHostname');
    }

    public function test_host_of_url(): void
    {
        $this->assertSame('api.example.com', Hosts::hostOf('https://api.example.com/path'));
        $this->assertSame('localhost', Hosts::hostOf('http://localhost:8000'));
        $this->assertNull(Hosts::hostOf(null));
        $this->assertNull(Hosts::hostOf('not a url'));
    }
}
