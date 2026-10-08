<?php

declare(strict_types=1);

namespace Access\Factory;

final class DiffGuard
{
    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function violations(string $role, string $featureDir, array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            $path = ltrim(str_replace('\\', '/', $file), '/');
            if ($path === '' || self::allowed($role, $featureDir, $path)) {
                continue;
            }

            $violations[] = $path;
        }

        return $violations;
    }

    private static function allowed(string $role, string $featureDir, string $path): bool
    {
        if ($role === 'reviewer') {
            return false;
        }

        if ($role === 'converge') {
            return $path === $featureDir.'/tasks.md';
        }

        if (self::isAlwaysProtected($path)) {
            return false;
        }

        if ($role === 'spec_author') {
            return self::authorPath($featureDir, $path);
        }

        if (self::under($path, 'specs')) {
            return self::under($path, $featureDir.'/.factory');
        }

        return true;
    }

    private static function authorPath(string $featureDir, string $path): bool
    {
        if (! self::under($path, $featureDir)) {
            return false;
        }

        $relative = substr($path, strlen($featureDir) + 1);
        $files = [
            'spec.md',
            'plan.md',
            'tasks.md',
            'research.md',
            'data-model.md',
            'quickstart.md',
            'constraints.md',
        ];

        return in_array($relative, $files, true)
            || self::under($relative, 'contracts')
            || self::under($relative, 'checklists')
            || self::under($relative, '.factory');
    }

    private static function isAlwaysProtected(string $path): bool
    {
        $trees = ['.specify', 'factory', '.cursor', '.github', '.gitlab'];
        foreach ($trees as $tree) {
            if (self::under($path, $tree)) {
                return true;
            }
        }

        return in_array($path, [
            '.gitlab-ci.yml',
            'Makefile',
            'phpstan.neon',
            'deptrac.php',
            'phpunit.xml',
        ], true);
    }

    private static function under(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }
}
