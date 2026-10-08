<?php

declare(strict_types=1);

namespace Access\Factory;

use InvalidArgumentException;

final class RunInput
{
    /**
     * @param  list<string>  $arguments
     */
    public static function description(string $root, array $arguments): string
    {
        if ($arguments === []) {
            throw new InvalidArgumentException('Pass a feature description or -i <file>.');
        }

        $path = self::inputPath($arguments);
        if ($path !== null) {
            return self::read($root, $path);
        }

        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('Pass one quoted description or -i <file>.');
        }

        $description = trim($arguments[0]);
        if ($description === '') {
            throw new InvalidArgumentException('The feature description is empty.');
        }

        return $description;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function inputPath(array $arguments): ?string
    {
        $flag = $arguments[0];
        if ($flag === '-i' || $flag === '--input') {
            if (count($arguments) !== 2 || $arguments[1] === '') {
                throw new InvalidArgumentException('Pass the brief path after -i.');
            }

            return $arguments[1];
        }

        if (str_starts_with($flag, '--input=')) {
            $path = substr($flag, strlen('--input='));
            if (count($arguments) !== 1 || $path === '') {
                throw new InvalidArgumentException('Pass the brief path after -i.');
            }

            return $path;
        }

        return null;
    }

    private static function read(string $root, string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $root.'/'.$path;
        if (! is_file($absolute)) {
            throw new InvalidArgumentException('Feature brief not found: '.$path);
        }

        $contents = file_get_contents($absolute);
        if (! is_string($contents) || trim($contents) === '') {
            throw new InvalidArgumentException('Feature brief is empty: '.$path);
        }

        return trim($contents);
    }
}
