<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\FrontendEnvMatches as Rule;

/**
 * The VITE_REVERB_* values the frontend is built with point browsers at the public side.
 */
final class FrontendEnvMatches extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
