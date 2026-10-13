<?php

declare(strict_types=1);

namespace Access\Factory;

final class ClaudeDriver implements AgentDriver
{
    public function __construct(
        private int $timeoutSeconds,
        private ?FactoryLog $log = null,
    ) {}

    public function name(): string
    {
        return 'claude';
    }

    public function sandboxPath(): string
    {
        return '.claude/settings.json';
    }

    public function sandboxConfig(string $role): array
    {
        $deny = array_map(
            static fn (string $path): string => 'Edit('.$path.')',
            SandboxPaths::forRole($role),
        );
        $deny[] = 'Edit(.claude/**)';

        return [
            'permissions' => [
                'allow' => [],
                'deny' => $deny,
            ],
        ];
    }

    public function command(string $cwd, string $model, string $prompt, ?string $chatId): array
    {
        $command = [
            'claude',
            '-p',
            '--output-format',
            'stream-json',
            '--model',
            $model,
            '--permission-mode',
            'bypassPermissions',
        ];

        if ($chatId !== null && $chatId !== '') {
            $command[] = '--resume';
            $command[] = $chatId;
        }

        $command[] = $prompt;

        return $command;
    }

    public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
    {
        $result = AgentProcess::run($this->command($cwd, $model, $prompt, $chatId), $cwd, $this->timeoutSeconds, $this->log);

        return $this->outcome($result['exitCode'], $result['stdout'], $result['stderr']);
    }

    public function parse(string $stream): AgentReply
    {
        $chatId = null;
        $assistant = '';
        $resultText = null;
        $isError = false;

        foreach (preg_split("/\r\n|\n|\r/", $stream) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            $sessionId = $event['session_id'] ?? null;
            if ($chatId === null && is_string($sessionId) && $sessionId !== '') {
                $chatId = $sessionId;
            }

            if (($event['type'] ?? null) === 'assistant') {
                $assistant .= self::assistantText($event);
            }

            if (($event['type'] ?? null) === 'result') {
                $isError = ($event['is_error'] ?? false) === true;
                $result = $event['result'] ?? null;
                $resultText = is_string($result) ? $result : null;
            }
        }

        $text = $resultText ?? $assistant;
        if ($text === '') {
            $fallback = trim($stream);
            if ($fallback !== '' && str_starts_with($fallback, '{')) {
                $text = $fallback;
            }
        }

        $reply = AgentReply::fromContractText($text, $chatId);
        if ($isError && $reply->status === 'unknown') {
            throw new FactoryStop('agent_failed', trim($resultText ?? ''));
        }

        return $reply;
    }

    public function outcome(int $exitCode, string $stdout, string $stderr): AgentReply
    {
        if ($exitCode !== 0 && trim($stdout) === '') {
            throw new FactoryStop('agent_failed', trim($stderr));
        }

        $reply = $this->parse($stdout);
        if ($exitCode !== 0 && $reply->status === 'unknown') {
            $detail = trim($stderr);

            throw new FactoryStop('agent_failed', $detail !== '' ? $detail : trim($stdout));
        }

        return $reply;
    }

    /**
     * @param  array<mixed>  $event
     */
    private static function assistantText(array $event): string
    {
        $content = $event['message']['content'] ?? null;
        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (! is_array($part) || ($part['type'] ?? null) !== 'text') {
                continue;
            }

            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text;
    }
}
