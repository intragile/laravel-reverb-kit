<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\ServerPathIsForwarded as Rule;

/**
 * A Reverb path prefix is also set on the broadcasting connection.
 */
final class ServerPathIsForwarded extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
