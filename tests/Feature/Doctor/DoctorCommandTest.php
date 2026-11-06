<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Tests\Feature\Doctor;

use Illuminate\Support\Facades\Artisan;
use IntrAgile\ReverbKit\Doctor\DoctorRunner;
use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Rule;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Tests\TestCase;
use RuntimeException;

final class DoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['reverb-kit.frontend_env' => __DIR__.'/missing.env']);
    }

    public function test_json_output_is_valid(): void
    {
        $this->requireReverb();
        config(Profiles::containerised());

        [$exit, $report] = $this->doctorJson();

        $this->assertSame(0, $exit);
        $this->assertSame('warning', $report['worst']);
        $this->assertCount(10, array_unique($this->ruleNames($report)));
    }

    public function test_exit_codes(): void
    {
        $this->requireReverb();
        config(Profiles::containerised());

        $this->assertSame(0, $this->doctor()[0]);
        $this->assertSame(1, $this->doctor(['--strict' => true])[0]);

        config(['broadcasting.connections.reverb.options.host' => '0.0.0.0']);
        [$exit, $output] = $this->doctor();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('publish-target', $output);
        $this->assertStringContainsString('REVERB_SERVER_HOST', $output);
    }

    public function test_replicas_option_overrides_config(): void
    {
        $this->requireReverb();
        config(Profiles::containerised());

        $this->assertSame(1, $this->doctor(['--replicas' => '3'])[0]);
        $this->assertSame(2, $this->doctor(['--replicas' => 'many'])[0]);
        $this->assertSame(2, $this->doctor(['--replicas' => '0'])[0]);
    }

    public function test_skip_option_omits_rule(): void
    {
        $this->requireReverb();
        config(Profiles::containerised());

        $this->assertSame(0, $this->doctor(['--strict' => true, '--skip' => ['allowed-origins']])[0]);

        config(['reverb-kit.doctor.skip' => ['allowed-origins']]);
        [, $report] = $this->doctorJson();

        $this->assertNotContains('allowed-origins', $this->ruleNames($report));
    }

    public function test_doctor_without_reverb_installed_exits_zero(): void
    {
        // An application that only publishes, to a Reverb server deployed elsewhere.
        config([
            'reverb' => null,
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'app-key',
            'broadcasting.connections.reverb.secret' => 'app-secret',
            'broadcasting.connections.reverb.app_id' => '100001',
            'broadcasting.connections.reverb.options.host' => 'reverb',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        [$exit, $report] = $this->doctorJson();

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $report['worst']);
    }

    public function test_missing_credentials_fail_even_without_reverb(): void
    {
        config(['reverb' => null, 'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]);

        [$exit, $output] = $this->doctor();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('connection-credentials', $output);
    }

    public function test_null_default_connection_skips_everything(): void
    {
        config(Profiles::withoutBroadcasting());

        [$exit, $output] = $this->doctor(['--strict' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Broadcasting default is "null", not the reverb connection; nothing to check.', $output);
    }

    public function test_rule_exception_becomes_failed_row(): void
    {
        $broken = new class implements Rule
        {
            public function name(): string
            {
                return 'broken';
            }

            public function check(ReverbSettings $settings): array
            {
                throw new RuntimeException('boom');
            }
        };
        $fine = new class implements Rule
        {
            public function name(): string
            {
                return 'fine';
            }

            public function check(ReverbSettings $settings): array
            {
                return [Finding::ok('fine', 'all good')];
            }
        };

        $runner = new DoctorRunner([$broken, $fine]);
        $report = $runner->run(ReverbSettings::fromConfig(config()));

        $this->assertSame('broken: RuntimeException: boom', $report->findings()[0]->message);
        $this->assertSame('failed', $report->toArray()['worst']);
        $this->assertSame('fine', $report->findings()[1]->rule);
        $this->assertCount(1, $runner->run(ReverbSettings::fromConfig(config()), ['broken'])->findings());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function doctor(array $options = []): array
    {
        $exit = Artisan::call('reverb:doctor', $options);

        return [$exit, Artisan::output()];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function doctorJson(array $options = []): array
    {
        [$exit, $output] = $this->doctor(['--json' => true] + $options);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        /** @var array<string, mixed> $report */
        return [$exit, $report];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function ruleNames(array $report): array
    {
        $findings = $report['findings'] ?? [];
        $this->assertIsArray($findings);

        $names = [];
        foreach ($findings as $finding) {
            if (is_array($finding) && is_string($finding['rule'] ?? null)) {
                $names[] = $finding['rule'];
            }
        }

        return $names;
    }

    private function requireReverb(): void
    {
        if (! class_exists('Laravel\Reverb\ReverbServiceProvider')) {
            $this->markTestSkipped('laravel/reverb is not installed.');
        }
    }
}
