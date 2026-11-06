<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor\LaravelDoctor;

use IntrAgile\ReverbKit\Doctor\DoctorRunner;
use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Doctor;
use Laravel\Doctor\Results\DiagnosticResult;

/**
 * Shows one reverb-kit rule inside laravel/doctor, in the "reverb" group.
 *
 * laravel/doctor is a 0.x package, so this directory is the only place that references it.
 */
abstract class ReverbKitDiagnostic extends Diagnostic
{
    /**
     * @var list<class-string<self>>
     */
    public const DIAGNOSTICS = [
        ConnectionCredentialsMatch::class,
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

    public string $group = 'reverb';

    /**
     * Registers the diagnostics with a resolved laravel/doctor instance.
     */
    public static function register(object $doctor): void
    {
        if ($doctor instanceof Doctor) {
            $doctor->diagnostics(self::DIAGNOSTICS);
        }
    }

    /**
     * @return class-string<Rule>
     */
    abstract protected function rule(): string;

    public function check(): DiagnosticResult
    {
        $rule = new ($this->rule());
        $skipped = config('reverb-kit.doctor.skip');

        if (is_array($skipped) && in_array($rule->name(), $skipped, true)) {
            return DiagnosticResult::skip('Skipped by reverb-kit.doctor.skip.', $this->code('skip'));
        }

        $report = (new DoctorRunner([$rule]))->run(ReverbSettings::fromConfig(config()));
        $findings = $report->findings();

        if ($findings === []) {
            return DiagnosticResult::skip('Nothing to check.', $this->code('skip'));
        }

        $worst = $report->worst();
        $decisive = array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->status === $worst));
        $summary = implode(' ', array_map(static fn (Finding $finding): string => $finding->message, $decisive));

        $result = match ($worst) {
            Status::Ok => DiagnosticResult::pass($summary, $this->code('pass')),
            Status::Skipped => DiagnosticResult::skip($summary, $this->code('skip')),
            Status::Warning => DiagnosticResult::warn($summary, $this->code('warn')),
            Status::Failed => DiagnosticResult::fail($summary, $this->code('fail')),
        };

        if (count($findings) > 1) {
            $result->withDetails(implode(PHP_EOL, array_map(
                static fn (Finding $finding): string => "- [{$finding->status->value}] {$finding->message}",
                $findings,
            )));
        }

        $hints = array_values(array_unique(array_filter(array_map(
            static fn (Finding $finding): ?string => $finding->hint,
            $decisive,
        ))));

        if ($hints !== [] && ($worst === Status::Warning || $worst === Status::Failed)) {
            $result->suggest(implode(' ', $hints));
        }

        return $result;
    }
}
