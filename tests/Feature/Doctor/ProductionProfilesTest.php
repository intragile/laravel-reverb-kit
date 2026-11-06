<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Doctor;

use IntrAgile\ReverbKit\Doctor\DoctorRunner;
use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Tests\TestCase;

final class ProductionProfilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Laravel\Reverb\ReverbServiceProvider')) {
            $this->markTestSkipped('laravel/reverb is not installed.');
        }
    }

    public function test_containerised_profile_only_warns_about_origins(): void
    {
        config(Profiles::containerised());

        $findings = $this->run_doctor();

        $this->assertSame([], $this->rules($findings, Status::Failed));
        $this->assertSame(['allowed-origins'], $this->rules($findings, Status::Warning));
    }

    public function test_profile_without_broadcasting_skips_everything(): void
    {
        config(Profiles::withoutBroadcasting());

        $findings = $this->run_doctor();

        $this->assertNotSame([], $findings);
        foreach ($findings as $finding) {
            $this->assertSame(Status::Skipped, $finding->status, "{$finding->rule}: {$finding->message}");
        }
    }

    public function test_misconfigured_profile_fails(): void
    {
        config(Profiles::containerised());
        config([
            'broadcasting.connections.reverb.options.host' => '0.0.0.0',
            'reverb.servers.reverb.host' => 'ws.example.com',
            'reverb-kit.topology.replicas' => 2,
        ]);

        $findings = $this->run_doctor();

        $this->assertSame(['publish-target', 'server-bind', 'scaling'], $this->rules($findings, Status::Failed));
    }

    /**
     * @return list<Finding>
     */
    private function run_doctor(): array
    {
        $settings = ReverbSettings::fromConfig(config(), __DIR__.'/missing.env');

        return app(DoctorRunner::class)->run($settings)->findings();
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private function rules(array $findings, Status $status): array
    {
        return array_values(array_unique(array_map(
            static fn (Finding $finding): string => $finding->rule,
            array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->status === $status)),
        )));
    }
}
