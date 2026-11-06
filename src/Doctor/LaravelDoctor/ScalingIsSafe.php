<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\ScalingIsSafe as Rule;

/**
 * Several replicas use scaling, on a channel no other deployment shares.
 */
final class ScalingIsSafe extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
