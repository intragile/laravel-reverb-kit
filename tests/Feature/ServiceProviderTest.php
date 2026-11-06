<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature;

use IntrAgile\ReverbKit\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertSame(1024, config('reverb-kit.publish.header_allowance'));
        $this->assertSame('reverb', config('reverb-kit.connection'));
    }
}
