<?php

declare(strict_types=1);

use Access\Factory\FactoryLog;
use Symfony\Component\Process\Process;

it('strips the verbose flag from the command arguments', function () {
    [$verbose, $arguments] = FactoryLog::extractVerbose([
        'orchestrate.php',
        'run',
        '-v',
        '-i',
        'specs/001.md',
    ]);

    expect($verbose)->toBeTrue()
        ->and($arguments)->toBe(['orchestrate.php', 'run', '-i', 'specs/001.md']);
});

it('strips the fast flag from the command arguments', function () {
    [$verbose, $fast, $arguments] = FactoryLog::extractFlags([
        'orchestrate.php',
        'run',
        '--fast',
        '-v',
        '-i',
        'specs/001.md',
    ]);

    expect($verbose)->toBeTrue()
        ->and($fast)->toBeTrue()
        ->and($arguments)->toBe(['orchestrate.php', 'run', '-i', 'specs/001.md']);
});

it('records actions and shows them when verbose', function () {
    $root = sys_get_temp_dir().'/access-factory-log-'.bin2hex(random_bytes(4));
    mkdir($root);
    $shown = '';

    try {
        $log = new FactoryLog($root, true, function (string $text) use (&$shown): void {
            $shown .= $text;
        });
        $log->info('step specify');
        $log->stream("{\"status\":\"done\"}\n");

        $file = (string) file_get_contents($root.'/factory/runs/orchestrator.log');
        expect($file)->toContain('step specify')
            ->and($shown)->toContain('step specify')
            ->and($shown)->toContain('{"status":"done"}');
    } finally {
        $process = new Process(['rm', '-rf', $root]);
        $process->run();
    }
});

it('keeps the terminal quiet without verbose except progress', function () {
    $root = sys_get_temp_dir().'/access-factory-log-'.bin2hex(random_bytes(4));
    mkdir($root);
    $shown = '';

    try {
        $log = new FactoryLog($root, false, function (string $text) use (&$shown): void {
            $shown .= $text;
        });
        $log->info('step plan');
        $log->progress('→ plan');
        $log->stream('hidden');

        expect($shown)->toContain('→ plan')
            ->and($shown)->not->toContain('step plan')
            ->and($shown)->not->toContain('hidden')
            ->and((string) file_get_contents($root.'/factory/runs/orchestrator.log'))->toContain('step plan')
            ->and((string) file_get_contents($root.'/factory/runs/orchestrator.log'))->toContain('→ plan');
    } finally {
        $process = new Process(['rm', '-rf', $root]);
        $process->run();
    }
});
