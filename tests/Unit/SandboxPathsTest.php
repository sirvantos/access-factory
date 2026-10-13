<?php

declare(strict_types=1);

use Access\Factory\SandboxPaths;

it('denies the tooling paths for every role', function () {
    expect(SandboxPaths::forRole('spec_author'))->toBe([
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
    ])->and(SandboxPaths::forRole('converge'))->toBe(SandboxPaths::forRole('spec_author'));
});

it('adds the spec files for implementer and feature tester', function () {
    $extra = [
        'specs/**/spec.md',
        'specs/**/plan.md',
        'specs/**/tasks.md',
        'specs/**/research.md',
        'specs/**/data-model.md',
        'specs/**/quickstart.md',
        'specs/**/constraints.md',
        'specs/**/contracts/**',
        'specs/**/checklists/**',
    ];

    expect(SandboxPaths::forRole('implementer'))->toBe([...SandboxPaths::forRole('spec_author'), ...$extra])
        ->and(SandboxPaths::forRole('feature_tester'))->toBe(SandboxPaths::forRole('implementer'));
});
