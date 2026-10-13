<?php

declare(strict_types=1);

it('constructs the driver named by the contract', function () {
    $binary = (string) file_get_contents(dirname(__DIR__, 2).'/bin/factory');

    expect($binary)->toContain('match ($contract->driver())')
        ->and($binary)->toContain("'cursor' => new CursorDriver")
        ->and($binary)->toContain("'claude' => new ClaudeDriver");
});
