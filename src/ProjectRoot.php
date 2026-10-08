<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;

final class ProjectRoot
{
    public static function find(?string $start = null): string
    {
        if ($start === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Cannot resolve the working directory.');
            }

            $start = $cwd;
        }

        $dir = $start;
        while (true) {
            if (is_file($dir.'/factory/factory.yaml')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                throw new RuntimeException('factory/factory.yaml was not found from '.$start.'.');
            }

            $dir = $parent;
        }
    }
}
