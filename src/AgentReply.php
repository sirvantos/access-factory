<?php

declare(strict_types=1);

namespace Access\Factory;

final class AgentReply
{
    /** @var list<string> */
    private const CONSTITUTION_RULES = [
        'constitution:I',
        'constitution:I.c',
        'constitution:I.a',
        'constitution:I.b',
        'constitution:II',
        'constitution:III',
        'constitution:IV',
        'constitution:V',
        'constitution:VI',
        'constitution:VI.a',
        'constitution:VII',
        'constitution:Conventions',
        'constitution:Prohibitions',
        'constitution:Definition of Done',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $status,
        public string $text,
        public ?string $chatId,
        public array $payload,
    ) {}

    public static function fromContractText(string $text, ?string $chatId): self
    {
        $text = trim($text);
        $payload = self::lastPayload($text);
        $status = 'unknown';
        if (isset($payload['status']) && is_string($payload['status'])) {
            $status = $payload['status'];
        } elseif (isset($payload['verdict']) && is_string($payload['verdict'])) {
            $status = $payload['verdict'];
        }

        return new self($status, $text, $chatId !== '' ? $chatId : null, $payload);
    }

    public function blocksApproval(): bool
    {
        $assumptions = $this->payload['assumptions'] ?? [];

        return is_array($assumptions) && $assumptions !== [];
    }

    public function reviewAccepted(string $mode = 'strict'): bool
    {
        if ($mode === 'strict' && $this->blocksApproval()) {
            return false;
        }

        if ($this->blockingScenarios($mode) !== []) {
            return false;
        }

        $issues = $this->blockingIssuesForMode($mode);
        if ($this->status === 'approve') {
            return $issues === [];
        }

        return $this->status === 'changes_requested' && $issues === [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blockingIssuesForMode(string $mode = 'strict'): array
    {
        $contract = new Contract(['implement_loop' => ['review_mode' => $mode]]);

        return $contract->blockingReviewIssues($this->keptIssues());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blockingScenarios(string $mode = 'strict'): array
    {
        $contract = new Contract(['implement_loop' => ['review_mode' => $mode]]);
        $scenarios = $this->payload['scenarios'] ?? [];
        if (! is_array($scenarios)) {
            return [];
        }

        $typed = [];
        foreach ($scenarios as $scenario) {
            if (is_array($scenario)) {
                /** @var array<string, mixed> $scenario */
                $typed[] = $scenario;
            }
        }

        return $contract->blockingReviewScenarios($typed);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    public static function issueSeverity(array $issue): string
    {
        return self::severity($issue);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actionableIssues(): array
    {
        $actionable = [];
        foreach ($this->keptIssues() as $issue) {
            if (self::severity($issue) === 'low') {
                continue;
            }

            $actionable[] = $issue;
        }

        return $actionable;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blockingIssues(): array
    {
        return $this->keptIssues();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discardedIssues(): array
    {
        $issues = $this->payload['issues'] ?? [];
        if (! is_array($issues)) {
            return [];
        }

        $discarded = [];
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $rule = $issue['rule'] ?? null;
            if (is_string($rule) && self::knownRule($rule)) {
                continue;
            }

            /** @var array<string, mixed> $issue */
            $discarded[] = $issue;
        }

        return $discarded;
    }

    public function discardedSummary(): string
    {
        $lines = [];
        foreach ($this->discardedIssues() as $issue) {
            $rule = $issue['rule'] ?? null;
            $rule = is_string($rule) && $rule !== '' ? $rule : '(none)';
            $file = is_string($issue['file'] ?? null) ? $issue['file'] : '';
            $line = $issue['line'] ?? null;
            $where = is_int($line) && $file !== '' ? $file.':'.$line : $file;
            $problem = is_string($issue['problem'] ?? null) ? $issue['problem'] : '';
            $label = $where !== '' ? $rule.' '.$where : $rule;
            $lines[] = $problem !== '' ? '- '.$label.': '.$problem : '- '.$label;
        }

        return implode("\n", $lines);
    }

    public static function analyzeRuleInstructions(): string
    {
        $rules = implode(', ', [...self::CONSTITUTION_RULES, 'plan:Module boundary exceptions', 'deptrac:<layer>']);

        return 'Every issue rule must be one of: '.$rules.'. Any other rule is discarded and is not repaired. A conflict between spec, plan, and tasks uses one of those rules. A module-boundary conflict uses plan:Module boundary exceptions.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keptIssues(): array
    {
        $issues = $this->payload['issues'] ?? [];
        if (! is_array($issues)) {
            return [];
        }

        $kept = [];
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $rule = $issue['rule'] ?? null;
            if (is_string($rule) && self::knownRule($rule)) {
                /** @var array<string, mixed> $issue */
                $kept[] = $issue;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private static function severity(array $issue): string
    {
        $severity = $issue['severity'] ?? null;
        if (! is_string($severity)) {
            return 'critical';
        }

        $severity = strtolower(trim($severity));
        if ($severity === 'middle') {
            $severity = 'medium';
        }

        if (in_array($severity, ['critical', 'high', 'medium', 'low'], true)) {
            return $severity;
        }

        return 'critical';
    }

    private static function knownRule(string $rule): bool
    {
        return in_array($rule, self::CONSTITUTION_RULES, true)
            || $rule === 'plan:Module boundary exceptions'
            || preg_match('/^deptrac:[A-Za-z][A-Za-z0-9]*$/', $rule) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private static function lastPayload(string $text): array
    {
        $payload = [];
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($cursor = $start; $cursor < $length; $cursor++) {
                $character = $text[$cursor];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($character === '"') {
                    $inString = true;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $decoded = json_decode(substr($text, $start, $cursor - $start + 1), true);
                        if (is_array($decoded) && (isset($decoded['status']) || isset($decoded['verdict']))) {
                            $payload = $decoded;
                        }
                        $start = $cursor;
                        break;
                    }
                }
            }
        }

        return $payload;
    }
}
