<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Support\Hosts;
use IntrAgile\ReverbKit\Templates\StubRenderer;
use IntrAgile\ReverbKit\Templates\TemplateValues;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the server, application and frontend environment blocks for Reverb, keeping the bind
 * address, the internal publish target and the public host apart.
 */
final class EnvCommand extends Command
{
    protected $signature = 'reverb:env
        {--public-host= : Host browsers connect to (default: reverb-kit.templates.public_host or the app.url host)}
        {--internal-host= : Host the application publishes to (default: reverb-kit.templates.upstream_host)}
        {--port= : Port Reverb listens on (default: reverb.servers.{server}.port)}';

    protected $description = 'Generate the Reverb environment variables for the server and the frontend';

    public function handle(Repository $config, StubRenderer $renderer): int
    {
        $overrides = [];

        foreach (['public-host' => 'public_host', 'internal-host' => 'upstream_host', 'port' => 'upstream_port'] as $option => $key) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $overrides[$key] = $value;
            }
        }

        if (isset($overrides['upstream_port']) && preg_match('/^[1-9]\d*$/', $overrides['upstream_port']) !== 1) {
            $this->components->error('--port must be a positive integer.');

            return self::FAILURE;
        }

        $values = TemplateValues::from(ReverbSettings::fromConfig($config), $config, $overrides);

        if (Hosts::isBindAddress($values['upstream_host'])) {
            $this->components->error("{$values['upstream_host']} is a bind address, not a host the application can publish to. Use the Reverb service name, e.g. reverb.");

            return self::FAILURE;
        }

        if (! Hosts::isPublicHostname($values['public_host'])) {
            $this->output->getErrorStyle()->warning("{$values['public_host']} is not a public hostname; browsers outside this machine cannot reach it. Pass --public-host.");
        }

        $this->output->write($renderer->render('env/reverb.env.stub', $values), false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
