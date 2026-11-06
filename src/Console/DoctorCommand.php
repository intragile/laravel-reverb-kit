<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\ReverbKit\Doctor\DoctorReport;
use IntrAgile\ReverbKit\Doctor\DoctorRunner;
use IntrAgile\ReverbKit\Doctor\Finding;
use IntrAgile\ReverbKit\Doctor\Status;
use IntrAgile\ReverbKit\Settings\ReverbSettings;

/**
 * Static check of the Reverb setup, meant to run as a deploy gate.
 *
 * Exit codes: 0 when nothing failed (warnings included, unless --strict), 1 on a failure or a
 * strict warning, 2 on invalid options.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'reverb:doctor
        {--json : Print the report as JSON}
        {--strict : Exit with 1 on warnings as well}
        {--frontend-env= : Path to the rendered frontend .env holding the VITE_REVERB_* values}
        {--replicas= : Number of Reverb replicas the deployment runs}
        {--skip=* : Rule names to leave out}';

    protected $description = 'Check the Reverb and broadcasting configuration for deployment mistakes';

    public function handle(DoctorRunner $runner, Repository $config): int
    {
        $replicas = $this->option('replicas');

        if ($replicas !== null && (! ctype_digit($replicas) || (int) $replicas < 1)) {
            $this->error('The --replicas option must be a positive integer.');

            return 2;
        }

        $frontendEnv = $this->option('frontend-env');
        $settings = ReverbSettings::fromConfig(
            $config,
            is_string($frontendEnv) && $frontendEnv !== '' ? $frontendEnv : null,
            $replicas === null ? null : (int) $replicas,
        );

        $report = $runner->run($settings, $this->skippedRules($config));

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($settings, $report);
        }

        return $report->exitCode((bool) $this->option('strict'));
    }

    /**
     * @return list<string>
     */
    private function skippedRules(Repository $config): array
    {
        $option = $this->option('skip');
        $configured = $config->get('reverb-kit.doctor.skip');

        return array_values(array_filter(
            array_merge($option, is_array($configured) ? $configured : []),
            'is_string',
        ));
    }

    private function render(ReverbSettings $settings, DoctorReport $report): void
    {
        if (! $settings->inUse()) {
            $this->line(sprintf(
                'Broadcasting default is "%s", not the %s connection; nothing to check.',
                $settings->defaultConnection ?? 'unset',
                $settings->connectionName,
            ));
            $this->newLine();
        }

        foreach ($report->findings() as $finding) {
            $this->line(sprintf('%s  %-22s %s', $this->badge($finding->status), $finding->rule, $finding->message));

            if ($finding->hint !== null && in_array($finding->status, [Status::Warning, Status::Failed], true)) {
                $this->line(str_repeat(' ', 32).'<fg=gray>→ '.$finding->hint.'</>');
            }
        }

        $this->newLine();
        $this->line($this->summary($report));
    }

    private function badge(Status $status): string
    {
        return match ($status) {
            Status::Ok => '<fg=green>  OK  </>',
            Status::Skipped => '<fg=gray> SKIP </>',
            Status::Warning => '<fg=yellow> WARN </>',
            Status::Failed => '<fg=red> FAIL </>',
        };
    }

    private function summary(DoctorReport $report): string
    {
        $counts = array_count_values(array_map(
            static fn (Finding $finding): string => $finding->status->value,
            $report->findings(),
        ));

        return sprintf(
            '%d failed, %d warning(s), %d ok, %d skipped.',
            $counts['failed'] ?? 0,
            $counts['warning'] ?? 0,
            $counts['ok'] ?? 0,
            $counts['skipped'] ?? 0,
        );
    }
}
