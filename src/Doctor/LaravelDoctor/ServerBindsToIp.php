<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\ServerBindsToIp as Rule;

/**
 * The Reverb server binds to an IP address.
 */
final class ServerBindsToIp extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
