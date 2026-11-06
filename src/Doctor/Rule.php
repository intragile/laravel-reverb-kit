<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor;

use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * One static check of a Reverb deployment.
 *
 * A rule reads only the settings snapshot it is given: no configuration lookups, no I/O and no
 * network, so the same rule can back both `reverb:doctor` and the laravel/doctor diagnostics.
 */
interface Rule
{
    /**
     * Stable kebab-case identifier, used by --skip and in JSON output.
     */
    public function name(): string;

    /**
     * @return list<Finding>
     */
    public function check(ReverbSettings $settings): array;
}
