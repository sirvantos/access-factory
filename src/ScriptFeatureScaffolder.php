<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ScriptFeatureScaffolder implements FeatureScaffolder
{
    public function create(string $root, string $description): array
    {
        $process = new Process([
            'bash',
            $root.'/.specify/scripts/bash/create-new-feature.sh',
            '--json',
            '--short-name',
            self::shortName($description),
            $description,
        ], $root);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
        }

        $payload = self::lastObject($process->getOutput());
        $specFile = $payload['SPEC_FILE'] ?? null;
        $branch = $payload['BRANCH_NAME'] ?? null;
        if (! is_string($specFile) || ! is_string($branch)) {
            throw new RuntimeException('Feature script did not return BRANCH_NAME and SPEC_FILE.');
        }

        if (str_starts_with($specFile, $root.'/')) {
            $specFile = substr($specFile, strlen($root) + 1);
        }

        return [
            'branch' => $branch,
            'dir' => dirname($specFile),
        ];
    }

    public static function shortName(string $description): string
    {
        $words = [];
        foreach (preg_split('/\s+/', strtolower($description)) ?: [] as $word) {
            $clean = preg_replace('/[^a-z0-9]+/', '', $word);
            if (is_string($clean) && $clean !== '') {
                $words[] = $clean;
            }

            if (count($words) === 4) {
                break;
            }
        }

        if ($words === []) {
            return 'feature-request';
        }

        if (count($words) < 2) {
            $words[] = 'feature';
        }

        return implode('-', $words);
    }

    /**
     * @return array<string, mixed>
     */
    private static function lastObject(string $output): array
    {
        $payload = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $payload = $decoded;
            }
        }

        return $payload;
    }
}
