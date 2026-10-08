<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class Contract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    public static function load(string $root): self
    {
        $parsed = Yaml::parseFile($root.'/factory/factory.yaml');
        if (! is_array($parsed)) {
            throw new RuntimeException('factory/factory.yaml must contain a map.');
        }

        /** @var array<string, mixed> $parsed */
        return new self($parsed);
    }

    public function model(string $role): string
    {
        $models = $this->data['models'] ?? null;
        $model = is_array($models) ? ($models[$role] ?? null) : null;
        if (! is_string($model) || $model === '' || $model === 'REQUIRED') {
            throw new RuntimeException('Model for '.$role.' is not set in factory/factory.yaml.');
        }

        return $model;
    }

    public function verifyCommand(): string
    {
        $command = $this->data['verify_command'] ?? null;

        return is_string($command) && $command !== '' ? $command : 'make verify';
    }

    public function maxAttempts(): int
    {
        $retries = $this->data['max_retries'] ?? 2;

        return (is_int($retries) ? $retries : 2) + 1;
    }

    public function maxConvergeRounds(): int
    {
        $rounds = $this->data['max_converge_rounds'] ?? 2;

        return is_int($rounds) ? $rounds : 2;
    }

    public function agentTimeout(): int
    {
        $timeout = $this->data['agent_timeout_seconds'] ?? 4800;

        return is_int($timeout) ? $timeout : 4800;
    }

    /**
     * Implement review strictness: strict | balanced | soft.
     */
    public function reviewMode(): string
    {
        $loop = $this->data['implement_loop'] ?? null;
        $mode = is_array($loop) ? ($loop['review_mode'] ?? null) : null;
        if (is_string($mode)) {
            $mode = strtolower(trim($mode));
            if (in_array($mode, ['strict', 'balanced', 'soft'], true)) {
                return $mode;
            }
        }

        return 'strict';
    }

    public function assumptionsBlockApproval(): bool
    {
        return $this->reviewMode() === 'strict';
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    public function issueBlocksReview(array $issue): bool
    {
        return match ($this->reviewMode()) {
            'balanced' => in_array(AgentReply::issueSeverity($issue), ['critical', 'high'], true),
            'soft' => in_array($issue['rule'] ?? null, [
                'constitution:Prohibitions',
                'constitution:Definition of Done',
            ], true),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public function scenarioBlocksReview(array $scenario): bool
    {
        $result = $scenario['result'] ?? null;
        if (! is_string($result) || $result === 'pass') {
            return false;
        }

        return match ($this->reviewMode()) {
            'soft' => $result === 'fail',
            default => true,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    public function blockingReviewIssues(array $issues): array
    {
        $blocking = [];
        foreach ($issues as $issue) {
            if ($this->issueBlocksReview($issue)) {
                $blocking[] = $issue;
            }
        }

        return $blocking;
    }

    /**
     * @param  list<array<string, mixed>>  $scenarios
     * @return list<array<string, mixed>>
     */
    public function blockingReviewScenarios(array $scenarios): array
    {
        $blocking = [];
        foreach ($scenarios as $scenario) {
            if ($this->scenarioBlocksReview($scenario)) {
                $blocking[] = $scenario;
            }
        }

        return $blocking;
    }

    public function reviewModeInstructions(): string
    {
        $mode = $this->reviewMode();

        $body = match ($mode) {
            'balanced' => <<<'TXT'
            Assumptions from the implementer are recorded and do not block approval.
            Only critical and high issues block approval. Medium and low findings are advisory.
            A scenario result of fail or missing_test blocks approval.
            TXT,
            'soft' => <<<'TXT'
            Assumptions from the implementer do not block approval.
            Only issues with rule constitution:Prohibitions or constitution:Definition of Done block approval. Every other finding is advisory.
            A scenario result of fail blocks approval. missing_test is advisory.
            TXT,
            default => <<<'TXT'
            Assumptions from the implementer block approval.
            Every kept issue blocks approval.
            A scenario result of fail or missing_test blocks approval.
            TXT,
        };

        return "Review mode: {$mode}.\n{$body}";
    }

    /**
     * Default factory run mode from yaml: full | fast.
     * CLI --fast overrides this for a new run and is stored on state.json.
     * fast keeps specify→clarify→plan→tasks→analyze→implement→converge,
     * but skips make verify and quality/functional code reviewers.
     */
    public function defaultMode(): string
    {
        $mode = $this->data['mode'] ?? 'full';
        if (is_string($mode)) {
            $mode = strtolower(trim($mode));
            if (in_array($mode, ['full', 'fast'], true)) {
                return $mode;
            }
        }

        return 'full';
    }

    /**
     * @return array<string, mixed>
     */
    public function cliConfig(string $role): array
    {
        $deny = [
            'Write(.specify/**)',
            'Write(.cursor/**)',
            'Write(factory/**)',
            'Write(.github/**)',
            'Write(.gitlab-ci.yml)',
            'Write(.gitlab/**)',
            'Write(Makefile)',
            'Write(phpstan.neon)',
            'Write(deptrac.php)',
            'Write(phpunit.xml)',
        ];

        if ($role === 'implementer' || $role === 'feature_tester') {
            array_push(
                $deny,
                'Write(specs/**/spec.md)',
                'Write(specs/**/plan.md)',
                'Write(specs/**/tasks.md)',
                'Write(specs/**/research.md)',
                'Write(specs/**/data-model.md)',
                'Write(specs/**/quickstart.md)',
                'Write(specs/**/constraints.md)',
                'Write(specs/**/contracts/**)',
                'Write(specs/**/checklists/**)',
            );
        }

        return [
            'permissions' => [
                'allow' => [],
                'deny' => $deny,
            ],
        ];
    }
}
