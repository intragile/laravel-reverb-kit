<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\Rules\PayloadLimitIsKnown as Rule;

/**
 * Reports the broadcast size limits and flags limits that contradict each other.
 */
final class PayloadLimitIsKnown extends ReverbKitDiagnostic
{
    protected function rule(): string
    {
        return Rule::class;
    }
}
