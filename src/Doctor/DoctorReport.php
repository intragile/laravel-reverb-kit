<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor;

/**
 * The outcome of a doctor run: every finding, in rule order.
 */
final readonly class DoctorReport
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(private array $findings) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * The most severe status in the report; an empty report is Ok.
     */
    public function worst(): Status
    {
        $worst = null;

        foreach ($this->findings as $finding) {
            if ($worst === null || $finding->status->severity() > $worst->severity()) {
                $worst = $finding->status;
            }
        }

        return $worst ?? Status::Ok;
    }

    /**
     * Process exit code for use as a deploy gate: failures always fail, warnings only when strict.
     */
    public function exitCode(bool $strict): int
    {
        return match ($this->worst()) {
            Status::Failed => 1,
            Status::Warning => $strict ? 1 : 0,
            Status::Ok, Status::Skipped => 0,
        };
    }

    /**
     * @return array{findings: list<array{rule: string, status: string, message: string, hint: string|null}>, worst: string}
     */
    public function toArray(): array
    {
        return [
            'findings' => array_map(static fn (Finding $finding): array => [
                'rule' => $finding->rule,
                'status' => $finding->status->value,
                'message' => $finding->message,
                'hint' => $finding->hint,
            ], $this->findings),
            'worst' => $this->worst()->value,
        ];
    }
}
