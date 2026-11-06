<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Unit\Doctor;

use IntrAgile\ReverbKit\Doctor\DoctorReport;
use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Status;
use PHPUnit\Framework\TestCase;

final class DoctorReportTest extends TestCase
{
    public function test_worst_ranks_skipped_below_ok(): void
    {
        $report = new DoctorReport([
            Finding::skipped('a', 'not in use'),
            Finding::ok('b', 'fine'),
        ]);

        $this->assertSame(Status::Ok, $report->worst());
        $this->assertSame(Status::Skipped, (new DoctorReport([Finding::skipped('a', 'x')]))->worst());
        $this->assertSame(Status::Failed, (new DoctorReport([
            Finding::warning('a', 'x'),
            Finding::failed('b', 'y'),
            Finding::ok('c', 'z'),
        ]))->worst());
    }

    public function test_exit_code_fails_only_on_failed_unless_strict(): void
    {
        $warning = new DoctorReport([Finding::ok('a', 'x'), Finding::warning('b', 'y')]);
        $failed = new DoctorReport([Finding::failed('a', 'x')]);
        $ok = new DoctorReport([Finding::ok('a', 'x'), Finding::skipped('b', 'y')]);

        $this->assertSame(0, $warning->exitCode(strict: false));
        $this->assertSame(1, $warning->exitCode(strict: true));
        $this->assertSame(1, $failed->exitCode(strict: false));
        $this->assertSame(0, $ok->exitCode(strict: true));
    }

    public function test_empty_report_is_ok(): void
    {
        $report = new DoctorReport([]);

        $this->assertSame(Status::Ok, $report->worst());
        $this->assertSame(0, $report->exitCode(strict: true));
    }

    public function test_to_array_shape(): void
    {
        $report = new DoctorReport([
            Finding::failed('publish-target', 'REVERB_HOST is 0.0.0.0', 'Use the service name.'),
            Finding::ok('scaling', 'scaling off, 1 replica'),
        ]);

        $this->assertSame([
            'findings' => [
                ['rule' => 'publish-target', 'status' => 'failed', 'message' => 'REVERB_HOST is 0.0.0.0', 'hint' => 'Use the service name.'],
                ['rule' => 'scaling', 'status' => 'ok', 'message' => 'scaling off, 1 replica', 'hint' => null],
            ],
            'worst' => 'failed',
        ], $report->toArray());
        $this->assertCount(2, $report->findings());
    }
}
