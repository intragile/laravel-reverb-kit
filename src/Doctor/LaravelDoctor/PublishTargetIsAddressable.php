<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\PublishTargetIsAddressable as Rule;

/**
 * Laravel publishes broadcasts to an address a client can connect to, not a bind address.
 */
final class PublishTargetIsAddressable extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
