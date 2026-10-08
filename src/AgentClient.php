<?php

declare(strict_types=1);

namespace Access\Factory;

interface AgentClient
{
    public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply;
}
