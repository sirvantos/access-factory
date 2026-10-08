<?php

declare(strict_types=1);

namespace Access\Factory;

use Closure;
use RuntimeException;

final class FactoryLog
{
    /**
     * @param  null|Closure(string): void  $console
     */
    public function __construct(
        private string $root,
        private bool $verbose = false,
        private ?Closure $console = null,
    ) {}

    /**
     * @param  list<string>  $argv
     * @return array{0: bool, 1: bool, 2: list<string>}
     */
    public static function extractFlags(array $argv): array
    {
        $verbose = false;
        $fast = false;
        $arguments = [];
        foreach ($argv as $argument) {
            if ($argument === '-v' || $argument === '--verbose') {
                $verbose = true;

                continue;
            }

            if ($argument === '--fast') {
                $fast = true;

                continue;
            }

            $arguments[] = $argument;
        }

        return [$verbose, $fast, $arguments];
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: bool, 1: list<string>}
     */
    public static function extractVerbose(array $argv): array
    {
        [$verbose, , $arguments] = self::extractFlags($argv);

        return [$verbose, $arguments];
    }

    public function info(string $message): void
    {
        $line = gmdate('H:i:s').' '.$message."\n";
        $this->append('orchestrator.log', $line);
        if ($this->verbose) {
            $this->emit($line);
        }
    }

    /**
     * Milestone progress: always written to the log and the terminal,
     * including when -v / --verbose is off.
     */
    public function progress(string $message): void
    {
        $line = gmdate('H:i:s').' '.$message."\n";
        $this->append('orchestrator.log', $line);
        $this->emit($line);
    }

    public function stream(string $chunk): void
    {
        if ($this->verbose && $chunk !== '') {
            $this->emit($chunk);
        }
    }

    private function emit(string $text): void
    {
        if ($this->console !== null) {
            ($this->console)($text);

            return;
        }

        fwrite(STDERR, $text);
        fflush(STDERR);
    }

    private function append(string $name, string $text): void
    {
        $directory = $this->root.'/factory/runs';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create '.$directory.'.');
        }

        $handle = fopen($directory.'/'.$name, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Could not write '.$directory.'/'.$name.'.');
        }

        fwrite($handle, $text);
        fflush($handle);
        fclose($handle);
    }
}
