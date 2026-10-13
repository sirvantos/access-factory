<?php

declare(strict_types=1);

namespace Access\Factory;

use Symfony\Component\Process\Process;

final class AgentProcess
{
    /**
     * @param  list<string>  $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(array $command, string $cwd, int $timeoutSeconds, ?FactoryLog $log): array
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeoutSeconds);
        $stdout = '';
        $process->run(function (string $type, string $buffer) use (&$stdout, $log): void {
            $stdout .= $buffer;
            $log?->stream($buffer);
        });

        return [
            'exitCode' => $process->getExitCode() ?? 1,
            'stdout' => $stdout,
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
