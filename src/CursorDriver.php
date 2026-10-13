<?php

declare(strict_types=1);

namespace Access\Factory;

final class CursorDriver implements AgentDriver
{
    public function __construct(
        private int $timeoutSeconds,
        private ?FactoryLog $log = null,
    ) {}

    public function name(): string
    {
        return 'cursor';
    }

    public function sandboxPath(): string
    {
        return '.cursor/cli.json';
    }

    public function sandboxConfig(string $role): array
    {
        return [
            'permissions' => [
                'allow' => [],
                'deny' => array_map(
                    static fn (string $path): string => 'Write('.$path.')',
                    SandboxPaths::forRole($role),
                ),
            ],
        ];
    }

    public function command(string $cwd, string $model, string $prompt, ?string $chatId): array
    {
        $command = [
            'cursor-agent',
            '-p',
            '--output-format',
            'stream-json',
            '--model',
            $model,
            '--trust',
            '--force',
            '--workspace',
            $cwd,
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
        $text = '';
        $chatId = null;

        foreach (preg_split("/\r\n|\n|\r/", $stream) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                $text .= $line."\n";

                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                $text .= $line."\n";

                continue;
            }

            $chatId ??= self::chatId($event);
            $rendered = self::eventText($event);
            if ($rendered !== '') {
                $text .= $rendered;
            } elseif (isset($event['status']) || isset($event['verdict'])) {
                $text .= $line."\n";
            }
        }

        return AgentReply::fromContractText($text, $chatId);
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
    private static function chatId(array $event): ?string
    {
        foreach (['session_id', 'sessionId', 'chat_id', 'chatId'] as $key) {
            $value = $event[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $event
     */
    private static function eventText(array $event): string
    {
        if (isset($event['result']) && is_string($event['result'])) {
            return $event['result']."\n";
        }

        $content = $event['message']['content'] ?? null;
        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text === '' ? '' : $text."\n";
    }
}
