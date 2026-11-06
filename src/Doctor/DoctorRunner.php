<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor;

use IntrAgile\ReverbKit\Settings\ReverbSettings;
use Throwable;

/**
 * Runs every rule against one settings snapshot and collects the findings.
 */
final readonly class DoctorRunner
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(private array $rules) {}

    /**
     * A rule that throws does not stop the run: it becomes a Failed finding naming the
     * exception, so a bug in one check never hides the result of the others.
     *
     * @param  list<string>  $skip  rule names to leave out of the report
     */
    public function run(ReverbSettings $settings, array $skip = []): DoctorReport
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            $name = $rule->name();

            if (in_array($name, $skip, true)) {
                continue;
            }

            try {
                array_push($findings, ...$rule->check($settings));
            } catch (Throwable $e) {
                $findings[] = Finding::failed($name, sprintf('%s: %s: %s', $name, $e::class, $e->getMessage()));
            }
        }

        return new DoctorReport($findings);
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
