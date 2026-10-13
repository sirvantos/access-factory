<?php

declare(strict_types=1);

use Access\Factory\FactoryState;

function statePayload(array $extra = []): array
{
    return [
        'feature_dir' => 'specs/001-demo',
        'branch' => '001-demo',
        'description' => 'Record a gate pass',
        'next' => 'specify',
        'stop' => null,
        'lock' => null,
        'converge_rounds' => 0,
        'tasks' => [],
        ...$extra,
    ];
}

it('round-trips the driver', function () {
    $state = FactoryState::fromArray(statePayload(['driver' => 'claude']));

    expect($state->driver)->toBe('claude')
        ->and($state->toArray()['driver'])->toBe('claude');
});

it('defaults a state file with no driver to cursor', function () {
    expect(FactoryState::fromArray(statePayload())->driver)->toBe('cursor');
});

it('rejects a stored driver it does not know', function () {
    expect(fn () => FactoryState::fromArray(statePayload(['driver' => 'codex'])))
        ->toThrow(RuntimeException::class, 'Factory state is unreadable.');
});
