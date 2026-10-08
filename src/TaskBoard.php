<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;

final class TaskBoard
{
    /**
     * @return list<TaskItem>
     */
    public static function parse(string $markdown): array
    {
        $phase = '0';
        $tasks = [];

        foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
            if (preg_match('/^## Phase (\d+)\b/', $line, $phaseMatch) === 1) {
                $phase = $phaseMatch[1];

                continue;
            }

            $task = self::parseLine($line, $phase);
            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    public static function markDone(string $markdown, string $taskId): string
    {
        $updated = preg_replace(
            '/^- \[[ xX]\] '.preg_quote($taskId, '/').'\b/m',
            '- [x] '.$taskId,
            $markdown,
            1,
        );

        return is_string($updated) ? $updated : $markdown;
    }

    /**
     * @param  list<TaskItem>  $tasks
     * @return list<TaskItem>
     */
    public static function order(array $tasks): array
    {
        $phaseOrder = [];
        $byId = [];

        foreach ($tasks as $task) {
            if (isset($byId[$task->id])) {
                throw new RuntimeException('Duplicate task id '.$task->id.'.');
            }

            $byId[$task->id] = $task;
            if (! in_array($task->phase, $phaseOrder, true)) {
                $phaseOrder[] = $task->phase;
            }
        }

        $phaseIndex = array_flip($phaseOrder);

        foreach ($tasks as $task) {
            foreach ($task->dependsOn as $dependency) {
                if (! isset($byId[$dependency])) {
                    throw new RuntimeException($task->id.' depends on missing task '.$dependency.'.');
                }

                if ($phaseIndex[$byId[$dependency]->phase] > $phaseIndex[$task->phase]) {
                    throw new RuntimeException($task->id.' depends on '.$dependency.', which is in a later phase.');
                }
            }
        }

        $ordered = [];

        foreach ($phaseOrder as $phase) {
            $subset = array_values(array_filter(
                $tasks,
                static fn (TaskItem $task): bool => $task->phase === $phase,
            ));
            array_push($ordered, ...self::orderPhase($subset));
        }

        return $ordered;
    }

    /**
     * @param  list<TaskItem>  $ordered
     * @return list<TaskItem>
     */
    public static function wave(array $ordered, int $limit): array
    {
        $done = [];
        foreach ($ordered as $task) {
            if ($task->done) {
                $done[$task->id] = true;
            }
        }

        $wave = [];
        $chosen = [];
        foreach ($ordered as $task) {
            if ($task->done || count($wave) >= $limit) {
                continue;
            }

            if (! self::readyForWave($task, $done, $chosen)) {
                continue;
            }

            $wave[] = $task;
            $chosen[$task->id] = true;
        }

        return $wave;
    }

    /**
     * @param  array<string, true>  $done
     * @param  array<string, true>  $chosen
     */
    private static function readyForWave(TaskItem $task, array $done, array $chosen): bool
    {
        foreach ($task->dependsOn as $dependency) {
            if (! isset($done[$dependency]) && ! isset($chosen[$dependency])) {
                return false;
            }
        }

        return true;
    }

    private static function parseLine(string $line, string $phase): ?TaskItem
    {
        if (preg_match('/^- \[(?<mark>[ xX])\] (?<id>T\d+)\b(?<rest>.*)$/', $line, $match) !== 1) {
            return null;
        }

        $rest = trim($match['rest']);
        $parallel = false;
        $story = null;

        while (preg_match('/^\[(?<token>P|US\d+)\]\s*(?<tail>.*)$/', $rest, $token) === 1) {
            if ($token['token'] === 'P') {
                $parallel = true;
            } else {
                $story = $token['token'];
            }

            $rest = trim($token['tail']);
        }

        $dependsOn = [];
        if (preg_match('/\s*\(depends on (?<deps>[^)]+)\)\s*$/', $rest, $deps) === 1) {
            $rest = trim(substr($rest, 0, -strlen($deps[0])));
            $dependsOn = self::parseDependencies($deps['deps']);
        }

        return new TaskItem(
            $match['id'],
            strtolower($match['mark']) === 'x',
            $parallel,
            $story,
            trim($rest),
            $dependsOn,
            $phase,
            trim($line),
        );
    }

    /**
     * Expand comma-separated dependency tokens, including inclusive ranges
     * like `T007-T014` or `T007–T014` (hyphen / en-dash / em-dash).
     *
     * @return list<string>
     */
    private static function parseDependencies(string $deps): array
    {
        $tokens = array_values(array_filter(array_map(
            static fn (string $dependency): string => trim($dependency),
            explode(',', $deps),
        ), static fn (string $dependency): bool => $dependency !== ''));

        $expanded = [];
        foreach ($tokens as $token) {
            foreach (self::expandDependencyToken($token) as $id) {
                $expanded[$id] = true;
            }
        }

        return array_keys($expanded);
    }

    /**
     * @return list<string>
     */
    private static function expandDependencyToken(string $token): array
    {
        if (preg_match('/^T(?<start>\d+)\s*[\-–—]\s*T(?<end>\d+)$/u', $token, $range) === 1) {
            $start = (int) $range['start'];
            $end = (int) $range['end'];
            if ($end < $start) {
                throw new RuntimeException('Invalid dependency range '.$token.'.');
            }

            $ids = [];
            for ($number = $start; $number <= $end; $number++) {
                $ids[] = 'T'.str_pad((string) $number, max(strlen($range['start']), strlen($range['end'])), '0', STR_PAD_LEFT);
            }

            return $ids;
        }

        return [$token];
    }

    /**
     * @param  list<TaskItem>  $tasks
     * @return list<TaskItem>
     */
    private static function orderPhase(array $tasks): array
    {
        $indegree = [];
        $dependents = [];
        $index = [];

        foreach ($tasks as $position => $task) {
            $indegree[$task->id] = 0;
            $dependents[$task->id] = [];
            $index[$task->id] = $position;
        }

        $ids = array_fill_keys(array_keys($indegree), true);

        foreach ($tasks as $task) {
            foreach ($task->dependsOn as $dependency) {
                if (! isset($ids[$dependency])) {
                    continue;
                }

                $indegree[$task->id]++;
                $dependents[$dependency][] = $task->id;
            }
        }

        $ready = array_keys(array_filter($indegree, static fn (int $degree): bool => $degree === 0));
        usort($ready, static fn (string $left, string $right): int => $index[$left] <=> $index[$right]);

        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->id] = $task;
        }

        $ordered = [];

        while ($ready !== []) {
            $id = array_shift($ready);
            $ordered[] = $byId[$id];

            foreach ($dependents[$id] as $dependent) {
                $indegree[$dependent]--;
                if ($indegree[$dependent] === 0) {
                    $ready[] = $dependent;
                    usort($ready, static fn (string $left, string $right): int => $index[$left] <=> $index[$right]);
                }
            }
        }

        if (count($ordered) !== count($tasks)) {
            throw new RuntimeException('Task dependencies contain a cycle.');
        }

        return $ordered;
    }
}
