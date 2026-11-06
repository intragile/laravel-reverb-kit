<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\ConnectionMatchesApp as Rule;

/**
 * The broadcasting connection's key, secret and app id match a Reverb app.
 */
final class ConnectionCredentialsMatch extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
