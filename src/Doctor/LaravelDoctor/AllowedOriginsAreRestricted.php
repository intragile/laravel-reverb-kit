<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\AllowedOriginsAreRestricted as Rule;

/**
 * Production apps do not accept WebSocket connections from any origin.
 */
final class AllowedOriginsAreRestricted extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
