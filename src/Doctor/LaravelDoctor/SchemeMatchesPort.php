<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\SchemeMatchesPort as Rule;

/**
 * Scheme, TLS flag and port of the connection and the apps agree with each other.
 */
final class SchemeMatchesPort extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
