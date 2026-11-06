<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Templates\StubRenderer;
use IntrAgile\ReverbKit\Templates\TemplateValues;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the Docker Swarm or Compose service definition for Reverb.
 */
final class StackCommand extends Command
{
    protected $signature = 'reverb:stack
        {format=swarm : swarm|compose}
        {--service=reverb : Service name}
        {--image= : Application image Reverb runs from}
        {--replicas= : Number of replicas (default: reverb-kit.topology.replicas)}
        {--scaling-channel= : Redis channel the replicas share}
        {--output= : Write to this file instead of stdout}
        {--force : Overwrite the --output file when it exists}';

    protected $description = 'Generate the Docker Swarm or Compose service for Reverb';

    private const STUBS = [
        'swarm' => 'stack/swarm.yml.stub',
        'compose' => 'stack/compose.yml.stub',
    ];

    public function handle(Repository $config, StubRenderer $renderer): int
    {
        $format = self::text($this->argument('format'));

        if ($format === null || ! array_key_exists($format, self::STUBS)) {
            $this->components->error('Unknown format. Use one of: '.implode(', ', array_keys(self::STUBS)).'.');

            return self::FAILURE;
        }

        $settings = ReverbSettings::fromConfig($config);
        $replicas = self::text($this->option('replicas')) ?? (string) $settings->replicas;

        if (preg_match('/^[1-9]\d*$/', $replicas) !== 1) {
            $this->components->error('--replicas must be a positive integer.');

            return self::FAILURE;
        }

        $service = $this->stringOption('service') ?? 'reverb';
        $overrides = ['service' => $service, 'replicas' => $replicas];
        $image = $this->stringOption('image');

        if ($image !== null) {
            $overrides['image'] = $image;
        } else {
            $this->output->getErrorStyle()->warning('No --image given; replace '.TemplateValues::DEFAULT_IMAGE.' with your application image.');
        }

        $channel = $this->stringOption('scaling-channel');

        if ($channel !== null) {
            $overrides['scaling_channel'] = $channel;
        }

        $values = TemplateValues::from($settings, $config, $overrides);

        if ($channel === null && (int) $replicas > 1 && $values['scaling_channel'] === 'reverb') {
            $app = Str::slug($this->stringConfig($config, 'app.name') ?? 'app', '_');
            $values['scaling_channel'] = "reverb_{$app}_{$service}";
            $this->output->getErrorStyle()->warning(
                "The scaling channel is Reverb's default \"reverb\", which every deployment on a shared Redis would join. "
                ."Using \"{$values['scaling_channel']}\"; pass --scaling-channel to choose another."
            );
        }

        return $this->emit($renderer->render(self::STUBS[$format], $values));
    }

    private function stringOption(string $name): ?string
    {
        return self::text($this->option($name));
    }

    private function emit(string $text): int
    {
        $target = $this->stringOption('output');

        if ($target === null) {
            $this->output->write($text, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if (file_exists($target) && $this->option('force') !== true) {
            $this->components->error("{$target} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        if (@file_put_contents($target, $text) === false) {
            $this->components->error("Could not write {$target}.");

            return self::FAILURE;
        }

        $this->output->getErrorStyle()->writeln("Wrote {$target}.");

        return self::SUCCESS;
    }

    private function stringConfig(Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Narrows a console input value; the declared type of arguments and options differs between
     * Laravel and Larastan versions, so it is checked at runtime instead.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
