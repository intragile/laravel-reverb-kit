<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Templates;

use InvalidArgumentException;

/**
 * Renders the generator stubs by replacing `{{ key }}` placeholders.
 *
 * A stub published to the application's `stubs/reverb-kit` directory takes precedence over the
 * copy shipped with the package, so projects can adapt the templates without forking.
 */
final class StubRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    public function __construct(
        private readonly ?string $overrideDirectory = null,
        private readonly ?string $packageDirectory = null,
    ) {}

    /**
     * @param  string  $stub  path relative to the stubs directory, e.g. `proxy/nginx.conf.stub`
     * @param  array<string, string>  $values
     *
     * @throws InvalidArgumentException when the stub is missing or a placeholder has no value
     */
    public function render(string $stub, array $values): string
    {
        return $this->fill($this->read($stub), $values, $stub);
    }

    /**
     * Fills a template in a single pass: inserted values are never scanned for placeholders.
     *
     * @param  array<string, string>  $values
     *
     * @throws InvalidArgumentException when a placeholder has no value
     */
    public function fill(string $template, array $values, ?string $source = null): string
    {
        $filled = preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values, $source): string {
            $key = $match[1];

            if (! array_key_exists($key, $values)) {
                throw new InvalidArgumentException(
                    "Unfilled placeholder {{ {$key} }}".($source !== null ? " in stub {$source}" : '').'.'
                );
            }

            return $values[$key];
        }, $template);

        if ($filled === null) {
            throw new InvalidArgumentException('Could not fill the template'.($source !== null ? " {$source}" : '').'.');
        }

        return $filled;
    }

    private function read(string $stub): string
    {
        $stub = ltrim($stub, '/');

        foreach ([$this->overrideDirectory(), $this->packageDirectory()] as $directory) {
            $path = $directory.'/'.$stub;

            if (is_file($path)) {
                $contents = file_get_contents($path);

                if ($contents === false) {
                    throw new InvalidArgumentException("Stub {$stub} could not be read from {$path}.");
                }

                return $contents;
            }
        }

        throw new InvalidArgumentException("Stub {$stub} was found neither in {$this->overrideDirectory()} nor in {$this->packageDirectory()}.");
    }

    private function overrideDirectory(): string
    {
        return rtrim($this->overrideDirectory ?? base_path('stubs/reverb-kit'), '/');
    }

    private function packageDirectory(): string
    {
        return rtrim($this->packageDirectory ?? dirname(__DIR__, 2).'/stubs', '/');
    }
}
