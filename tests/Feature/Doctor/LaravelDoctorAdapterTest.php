<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Doctor;

use Illuminate\Support\Facades\Artisan;
use IntrAgile\ReverbKit\Doctor\LaravelDoctor\ReverbKitDiagnostic;
use IntrAgile\ReverbKit\Doctor\RuleSet;
use IntrAgile\ReverbKit\Tests\TestCase;

final class LaravelDoctorAdapterTest extends TestCase
{
    private const DOCTOR_PROVIDER = 'Laravel\Doctor\DoctorServiceProvider';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(self::DOCTOR_PROVIDER) || ! class_exists('Laravel\Reverb\ReverbServiceProvider')) {
            $this->markTestSkipped('laravel/doctor and laravel/reverb are required.');
        }

        config(['reverb-kit.frontend_env' => __DIR__.'/missing.env']);
    }

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists(self::DOCTOR_PROVIDER)) {
            $providers[] = self::DOCTOR_PROVIDER;
        }

        return $providers;
    }

    public function test_one_diagnostic_per_rule(): void
    {
        $this->assertCount(count(RuleSet::DEFAULT), ReverbKitDiagnostic::DIAGNOSTICS);
    }

    public function test_doctor_lists_reverb_diagnostics_and_fails_on_bind_address(): void
    {
        config(Profiles::containerised());
        config(['broadcasting.connections.reverb.options.host' => '0.0.0.0']);

        Artisan::call('doctor', ['--only' => ['reverb'], '--format' => 'json', '--fail-on' => 'never']);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $results = $this->results($report);
        $this->assertCount(10, $results, $output);

        $failed = array_values(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'fail'));
        $this->assertCount(1, $failed, $output);
        $this->assertStringContainsString('0.0.0.0', (string) json_encode($failed[0]));
    }

    /**
     * @param  array<mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function results(array $report): array
    {
        $rows = $report['results'] ?? $report['diagnostics'] ?? $report;
        $this->assertIsArray($rows);

        $results = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $results[] = $row;
            }
        }

        return $results;
    }
}
