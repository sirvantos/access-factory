<?php

declare(strict_types=1);

use Access\Factory\AgentProcess;
use Access\Factory\FactoryLog;
use Symfony\Component\Process\Process;

it('streams every process buffer to the log', function () {
    $root = sys_get_temp_dir().'/access-factory-process-'.bin2hex(random_bytes(4));
    mkdir($root);
    $seen = '';
    $log = new FactoryLog($root, true, function (string $chunk) use (&$seen): void {
        $seen .= $chunk;
    });

    try {
        $result = AgentProcess::run([PHP_BINARY, '-r', 'echo "hello";'], $root, 10, $log);

        expect($result['exitCode'])->toBe(0)
            ->and($result['stdout'])->toBe('hello')
            ->and($seen)->toBe('hello');
    } finally {
        $process = new Process(['rm', '-rf', $root]);
        $process->run();
    }
});
