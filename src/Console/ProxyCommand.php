<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\ReverbKit\Settings\ReverbSettings;
use IntrAgile\ReverbKit\Templates\StubRenderer;
use IntrAgile\ReverbKit\Templates\TemplateValues;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the reverse proxy configuration that forwards WebSocket traffic to Reverb.
 */
final class ProxyCommand extends Command
{
    protected $signature = 'reverb:proxy
        {server=nginx : nginx|caddy|traefik}
        {--upstream= : Host the proxy reaches Reverb on (default: reverb-kit.templates.upstream_host)}
        {--public-host= : Host browsers connect to (default: reverb-kit.templates.public_host or the app.url host)}
        {--with-api : Also expose the HTTP API under /apps (nginx only)}
        {--output= : Write to this file instead of stdout}
        {--force : Overwrite the --output file when it exists}';

    protected $description = 'Generate the nginx, Caddy or Traefik configuration for Reverb';

    private const STUBS = [
        'nginx' => 'proxy/nginx.conf.stub',
        'caddy' => 'proxy/caddy.stub',
        'traefik' => 'proxy/traefik-labels.yml.stub',
    ];

    private const API_LOCATION = <<<'NGINX'

# Reverb HTTP API, used to publish events. Only needed when the publisher runs outside the
# internal network; inside it, publish to {{ upstream_host }}:{{ upstream_port }} directly.
# Every request is authenticated by its signature (the app secret), not by this proxy.
location {{ path }}/apps/ {
    resolver {{ resolver }} valid=10s;
    set $reverb_upstream "{{ upstream_host }}:{{ upstream_port }}";

    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_pass http://$reverb_upstream;
}

NGINX;

    public function handle(Repository $config, StubRenderer $renderer): int
    {
        $server = $this->argument('server');

        if (! is_string($server) || ! array_key_exists($server, self::STUBS)) {
            $this->components->error('Unknown server. Use one of: '.implode(', ', array_keys(self::STUBS)).'.');

            return self::FAILURE;
        }

        $overrides = [];

        foreach (['upstream' => 'upstream_host', 'public-host' => 'public_host'] as $option => $key) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $overrides[$key] = $value;
            }
        }

        $values = TemplateValues::from(ReverbSettings::fromConfig($config), $config, $overrides);

        if ($this->option('with-api') === true) {
            if ($server === 'nginx') {
                $values['api_location'] = $renderer->fill(self::API_LOCATION, $values);
            } else {
                $this->output->getErrorStyle()->warning("--with-api only applies to nginx; route {$values['path']}/apps/ by hand for {$server}.");
            }
        }

        return $this->emit($renderer->render(self::STUBS[$server], $values));
    }

    private function emit(string $text): int
    {
        $target = $this->option('output');

        if (! is_string($target) || $target === '') {
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
}
