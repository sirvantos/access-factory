<?php

declare(strict_types=1);

use Access\Factory\RunInput;
use Symfony\Component\Process\Process;

it('reads a feature brief from -i', function () {
    $root = sys_get_temp_dir().'/access-factory-input-'.bin2hex(random_bytes(4));
    mkdir($root.'/specs', 0777, true);
    file_put_contents($root.'/specs/001.md', "# Record a gate pass\n\nA card opens the turnstile.\n");

    try {
        expect(RunInput::description($root, ['-i', 'specs/001.md']))
            ->toBe("# Record a gate pass\n\nA card opens the turnstile.")
            ->and(RunInput::description($root, ['--input', 'specs/001.md']))
            ->toBe("# Record a gate pass\n\nA card opens the turnstile.")
            ->and(RunInput::description($root, ['--input=specs/001.md']))
            ->toBe("# Record a gate pass\n\nA card opens the turnstile.");
    } finally {
        $process = new Process(['rm', '-rf', $root]);
        $process->run();
    }
});

it('keeps a quoted description', function () {
    expect(RunInput::description('/tmp', ['Record a gate pass']))->toBe('Record a gate pass');
});

it('rejects a missing brief', function () {
    expect(fn () => RunInput::description('/tmp', ['-i', 'specs/missing.md']))
        ->toThrow(InvalidArgumentException::class, 'not found');
});
