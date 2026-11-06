<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\ReverbIsPatched as Rule;

/**
 * The installed Reverb release is not affected by a known security advisory.
 */
final class ReverbIsPatched extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
