<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor;

use IntrAgile\ReverbKit\Doctor\Rules\AllowedOriginsAreRestricted;
use IntrAgile\ReverbKit\Doctor\Rules\ConnectionMatchesApp;
use IntrAgile\ReverbKit\Doctor\Rules\FrontendEnvMatches;
use IntrAgile\ReverbKit\Doctor\Rules\PayloadLimitIsKnown;
use IntrAgile\ReverbKit\Doctor\Rules\PublishTargetIsAddressable;
use IntrAgile\ReverbKit\Doctor\Rules\ReverbIsPatched;
use IntrAgile\ReverbKit\Doctor\Rules\ScalingIsSafe;
use IntrAgile\ReverbKit\Doctor\Rules\SchemeMatchesPort;
use IntrAgile\ReverbKit\Doctor\Rules\ServerBindsToIp;
use IntrAgile\ReverbKit\Doctor\Rules\ServerPathIsForwarded;

/**
 * The rules the doctor runs, in report order: what the application sends first, then how the
 * server runs, then what the browser is told.
 */
final class RuleSet
{
    /**
     * @var list<class-string<Rule>>
     */
    public const DEFAULT = [
        ConnectionMatchesApp::class,
        PublishTargetIsAddressable::class,
        SchemeMatchesPort::class,
        ServerPathIsForwarded::class,
        ServerBindsToIp::class,
        ScalingIsSafe::class,
        AllowedOriginsAreRestricted::class,
        PayloadLimitIsKnown::class,
        ReverbIsPatched::class,
        FrontendEnvMatches::class,
    ];

    /**
     * @return list<Rule>
     */
    public static function make(): array
    {
        return array_map(static fn (string $class): Rule => new $class, self::DEFAULT);
    }
}
