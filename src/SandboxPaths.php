<?php

declare(strict_types=1);

namespace Access\Factory;

final class SandboxPaths
{
    /**
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        $paths = [
            '.specify/**',
            '.cursor/**',
            'factory/**',
            '.github/**',
            '.gitlab-ci.yml',
            '.gitlab/**',
            'Makefile',
            'phpstan.neon',
            'deptrac.php',
            'phpunit.xml',
        ];

        if ($role === 'implementer' || $role === 'feature_tester') {
            array_push(
                $paths,
                'specs/**/spec.md',
                'specs/**/plan.md',
                'specs/**/tasks.md',
                'specs/**/research.md',
                'specs/**/data-model.md',
                'specs/**/quickstart.md',
                'specs/**/constraints.md',
                'specs/**/contracts/**',
                'specs/**/checklists/**',
            );
        }

        return $paths;
    }
}
