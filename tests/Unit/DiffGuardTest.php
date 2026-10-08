<?php

declare(strict_types=1);

use Access\Factory\DiffGuard;

it('keeps each role inside its write set', function (string $role, string $path, bool $allowed) {
    $violations = DiffGuard::violations($role, 'specs/001-demo', [$path]);

    expect($violations === [])->toBe($allowed);
})->with([
    ['spec_author', 'specs/001-demo/spec.md', true],
    ['spec_author', 'specs/001-demo/contracts/pass.yaml', true],
    ['spec_author', 'Makefile', false],
    ['spec_author', 'app/Models/Pass.php', false],
    ['implementer', 'app/Models/Pass.php', true],
    ['implementer', 'specs/001-demo/.factory/questions.md', true],
    ['implementer', 'specs/001-demo/spec.md', false],
    ['implementer', 'factory/factory.yaml', false],
    ['reviewer', 'app/Models/Pass.php', false],
    ['converge', 'specs/001-demo/tasks.md', true],
    ['converge', 'specs/001-demo/spec.md', false],
]);
