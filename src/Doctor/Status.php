<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor;

/**
 * Verdict of one doctor finding.
 */
enum Status: string
{
    case Ok = 'ok';
    case Skipped = 'skipped';
    case Warning = 'warning';
    case Failed = 'failed';

    /**
     * Ordering used to pick the worst finding.
     *
     * Skipped ranks below Ok: a rule that does not apply must not hide that the rest passed.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Skipped => 0,
            self::Ok => 1,
            self::Warning => 2,
            self::Failed => 3,
        };
    }
}
