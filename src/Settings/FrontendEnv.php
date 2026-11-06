<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Settings;

use Dotenv\Parser\Parser;
use Dotenv\Parser\Value;
use Throwable;

/**
 * Reads the VITE_REVERB_* values the frontend bundle is built with.
 *
 * The file is parsed, never loaded: nothing is written to the environment and `${VAR}`
 * references are kept as written, because they are resolved by whatever builds the frontend.
 */
final class FrontendEnv
{
    private const PREFIX = 'VITE_REVERB_';

    /**
     * @return array<string, string>|null null when the file is missing, unreadable or has no VITE_REVERB_* key
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $entries = (new Parser)->parse($contents);
        } catch (Throwable) {
            return null;
        }

        $values = [];

        foreach ($entries as $entry) {
            if (! str_starts_with($entry->getName(), self::PREFIX)) {
                continue;
            }

            $values[$entry->getName()] = $entry->getValue()->map(
                static fn (Value $value): string => $value->getChars(),
            )->getOrElse('');
        }

        return $values === [] ? null : $values;
    }
}
