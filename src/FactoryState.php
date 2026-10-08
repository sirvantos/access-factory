<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;

final class FactoryState
{
    /**
     * @param  array{reason: string, detail: string, resume_at: string}|null  $stop
     * @param  array{pid: int, at: string}|null  $lock
     * @param  array<string, array{attempts: int, status: string, chat_id: string|null, spec_hash: string, feature_chat_id: string|null}>  $tasks
     */
    public function __construct(
        public string $featureDir,
        public string $branch,
        public string $description,
        public string $next,
        public ?array $stop,
        public ?array $lock,
        public int $convergeRounds,
        public array $tasks,
        public ?string $authorChatId = null,
        public ?string $authorModel = null,
        public string $mode = 'full',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $featureDir = $data['feature_dir'] ?? null;
        $branch = $data['branch'] ?? null;
        $next = $data['next'] ?? null;
        if (! is_string($featureDir) || ! is_string($branch) || ! is_string($next)) {
            throw new RuntimeException('Factory state is unreadable.');
        }

        $description = $data['description'] ?? '';
        $rounds = $data['converge_rounds'] ?? 0;
        $authorChatId = $data['author_chat_id'] ?? null;
        $authorModel = $data['author_model'] ?? null;
        $mode = $data['mode'] ?? 'full';
        if (! is_string($mode) || ! in_array($mode, ['full', 'fast'], true)) {
            $mode = 'full';
        }

        return new self(
            $featureDir,
            $branch,
            is_string($description) ? $description : '',
            $next,
            self::stop($data['stop'] ?? null),
            self::lock($data['lock'] ?? null),
            is_int($rounds) ? $rounds : 0,
            self::tasks($data['tasks'] ?? []),
            is_string($authorChatId) && $authorChatId !== '' ? $authorChatId : null,
            is_string($authorModel) && $authorModel !== '' ? $authorModel : null,
            $mode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feature_dir' => $this->featureDir,
            'branch' => $this->branch,
            'description' => $this->description,
            'next' => $this->next,
            'stop' => $this->stop,
            'lock' => $this->lock,
            'converge_rounds' => $this->convergeRounds,
            'tasks' => $this->tasks,
            'author_chat_id' => $this->authorChatId,
            'author_model' => $this->authorModel,
            'mode' => $this->mode,
        ];
    }

    public function isFast(): bool
    {
        return $this->mode === 'fast';
    }

    /**
     * @return array{attempts: int, status: string, chat_id: string|null, spec_hash: string, feature_chat_id: string|null}
     */
    public function task(string $id): array
    {
        $record = $this->tasks[$id] ?? [
            'attempts' => 0,
            'status' => 'pending',
            'chat_id' => null,
            'spec_hash' => '',
            'feature_chat_id' => null,
        ];
        $record['feature_chat_id'] ??= null;

        return $record;
    }

    public function putTask(string $id, int $attempts, string $status, ?string $chatId, string $specHash, ?string $featureChatId = null): void
    {
        $previous = $this->tasks[$id]['feature_chat_id'] ?? null;

        $this->tasks[$id] = [
            'attempts' => $attempts,
            'status' => $status,
            'chat_id' => $chatId,
            'spec_hash' => $specHash,
            'feature_chat_id' => $featureChatId ?? (is_string($previous) ? $previous : null),
        ];
    }

    /**
     * @return array{reason: string, detail: string, resume_at: string}|null
     */
    private static function stop(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $reason = $value['reason'] ?? null;
        $detail = $value['detail'] ?? null;
        $resumeAt = $value['resume_at'] ?? '';

        if (! is_string($reason) || ! is_string($detail) || ! is_string($resumeAt)) {
            return null;
        }

        return [
            'reason' => $reason,
            'detail' => $detail,
            'resume_at' => $resumeAt,
        ];
    }

    /**
     * @return array{pid: int, at: string}|null
     */
    private static function lock(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $pid = $value['pid'] ?? null;
        $at = $value['at'] ?? null;
        if (! is_int($pid) || ! is_string($at)) {
            return null;
        }

        return ['pid' => $pid, 'at' => $at];
    }

    /**
     * @return array<string, array{attempts: int, status: string, chat_id: string|null, spec_hash: string, feature_chat_id: string|null}>
     */
    private static function tasks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tasks = [];
        foreach ($value as $id => $record) {
            if (! is_string($id) || ! is_array($record)) {
                continue;
            }

            $attempts = $record['attempts'] ?? 0;
            $status = $record['status'] ?? 'pending';
            $chatId = $record['chat_id'] ?? null;
            $featureChatId = $record['feature_chat_id'] ?? null;
            $hash = $record['spec_hash'] ?? '';
            $tasks[$id] = [
                'attempts' => is_int($attempts) ? $attempts : 0,
                'status' => is_string($status) ? $status : 'pending',
                'chat_id' => is_string($chatId) ? $chatId : null,
                'spec_hash' => is_string($hash) ? $hash : '',
                'feature_chat_id' => is_string($featureChatId) ? $featureChatId : null,
            ];
        }

        return $tasks;
    }
}
