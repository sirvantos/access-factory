<?php

declare(strict_types=1);

namespace Access\Factory;

interface AgentDriver extends AgentClient
{
    public function name(): string;

    public function sandboxPath(): string;

    /**
     * @return array<string, mixed>
     */
    public function sandboxConfig(string $role): array;

    /**
     * @return list<string>
     */
    public function command(string $cwd, string $model, string $prompt, ?string $chatId): array;

    public function parse(string $stream): AgentReply;

    public function outcome(int $exitCode, string $stdout, string $stderr): AgentReply;
}
