<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests;

use IntrAgile\ReverbKit\ReverbKitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    private const REVERB_PROVIDER = 'Laravel\Reverb\ReverbServiceProvider';

    /**
     * Reverb is optional: when it is installed its provider loads the `reverb` configuration the
     * server-side rules read, when it is not the package must work without it.
     */
    protected function getPackageProviders($app): array
    {
        return class_exists(self::REVERB_PROVIDER)
            ? [self::REVERB_PROVIDER, ReverbKitServiceProvider::class]
            : [ReverbKitServiceProvider::class];
    }
}
