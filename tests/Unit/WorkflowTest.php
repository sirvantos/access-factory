<?php

declare(strict_types=1);

use Access\Factory\AgentClient;
use Access\Factory\AgentReply;
use Access\Factory\Contract;
use Access\Factory\FactoryLog;
use Access\Factory\FactoryState;
use Access\Factory\FactoryStop;
use Access\Factory\FeatureScaffolder;
use Access\Factory\GitRepo;
use Access\Factory\ScriptFeatureScaffolder;
use Access\Factory\Workflow;
use Symfony\Component\Process\Process;

it('names a feature from ascii words and falls back for other text', function () {
    expect(ScriptFeatureScaffolder::shortName('Record a gate pass'))->toBe('record-a-gate-pass')
        ->and(ScriptFeatureScaffolder::shortName('Сотрудник отмечает проход'))->toBe('feature-request');
});

it('runs specify through converge with a scripted agent', function () {
    $root = factoryRoot();

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        $workflow = factoryWorkflow($root, $agent);
        $workflow->start('Record a gate pass');

        $tracked = new Process(['git', 'ls-files', '--', '.specify/feature.json'], $root);
        $tracked->mustRun();

        expect($workflow->statusText())->toBe('specs/001-demo done')
            ->and(trim($tracked->getOutput()))->toBe('')
            ->and(factoryLog($root))->toBe("implement specs/001-demo\nspec specs/001-demo\nignore local files\ninit")
            ->and(file_get_contents($root.'/recorded.txt'))->toBe("pass\n")
            ->and(file_get_contents($root.'/specs/001-demo/tasks.md'))->toContain('- [x] T001');

        $spec = (string) file_get_contents($root.'/specs/001-demo/spec.md');
        file_put_contents($root.'/specs/001-demo/spec.md', $spec."\nExtra.\n");

        expect($workflow->staleIds())->toBe(['T001']);

        file_put_contents($root.'/specs/001-demo/spec.md', $spec);
        $tasks = (string) file_get_contents($root.'/specs/001-demo/tasks.md');
        file_put_contents($root.'/specs/001-demo/tasks.md', str_replace('- [x] T001', '- [ ] T001', $tasks));

        expect($workflow->staleIds())->toBe([]);
    } finally {
        factoryRemove($root);
    }
});

it('prints stage milestones without verbose', function () {
    $root = factoryRoot();
    $shown = '';

    try {
        $log = new FactoryLog($root, false, function (string $text) use (&$shown): void {
            $shown .= $text;
        });

        $agent = factoryAgent(function (string $cwd, string $prompt): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 0, 'true', 'strict', 'full', $log)->start('Record a gate pass');

        expect($shown)->toContain('→ specify')
            ->and($shown)->toContain('specify done')
            ->and($shown)->toContain('→ clarify')
            ->and($shown)->toContain('clarify done')
            ->and($shown)->toContain('→ plan')
            ->and($shown)->toContain('plan done')
            ->and($shown)->toContain('→ tasks')
            ->and($shown)->toContain('tasks done (1 tasks)')
            ->and($shown)->toContain('→ analyze')
            ->and($shown)->toContain('analyze approve (0 open)')
            ->and($shown)->toContain('→ implement')
            ->and($shown)->toContain('wave T001 drafted (1/1)')
            ->and($shown)->toContain('implement 1/1')
            ->and($shown)->toContain('→ converge')
            ->and($shown)->toContain('converge no new tasks → done')
            ->and($shown)->not->toContain('worktree ');
    } finally {
        factoryRemove($root);
    }
});

it('keeps analyze in fast mode but skips verify and code reviews', function () {
    $root = factoryRoot();
    $prompts = [];
    $verify = 'sh -c '.escapeshellarg('echo VERIFY_RAN; exit 1');

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$prompts): string {
            $prompts[] = $prompt;
            $dir = 'specs/001-demo';

            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"changes_requested","issues":[{"severity":"critical","rule":"constitution:Definition of Done","location":"x","description":"should not run"}],"scenarios":[]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        $workflow = factoryWorkflow($root, $agent, null, 0, $verify, 'strict', 'full');
        $workflow->start('Record a gate pass', 'fast');

        $state = json_decode((string) file_get_contents($root.'/specs/001-demo/.factory/state.json'), true);

        expect($workflow->statusText())->toBe('specs/001-demo done')
            ->and($state['mode'] ?? null)->toBe('fast')
            ->and(file_get_contents($root.'/recorded.txt'))->toBe("pass\n")
            ->and(file_get_contents($root.'/specs/001-demo/tasks.md'))->toContain('- [x] T001')
            ->and(array_any($prompts, fn (string $prompt): bool => str_contains($prompt, 'speckit-analyze')))->toBeTrue()
            ->and(array_any($prompts, fn (string $prompt): bool => str_contains($prompt, 'QUALITY_REVIEW')))->toBeFalse()
            ->and(array_any($prompts, fn (string $prompt): bool => str_contains($prompt, 'FUNCTIONAL_REVIEW')))->toBeFalse();
    } finally {
        factoryRemove($root);
    }
});

it('stops when clarify needs a human answer', function () {
    $root = factoryRoot();

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt): string {
            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/specs/001-demo/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-clarify')) {
                return '{"status":"spec_gap","summary":"Which gate?","assumptions":[]}';
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        $workflow = factoryWorkflow($root, $agent);

        expect(fn () => $workflow->start('Record a gate pass'))
            ->toThrow(FactoryStop::class, 'questions.md');

        expect(file_get_contents($root.'/specs/001-demo/.factory/questions.md'))->toContain('Which gate?')
            ->and($workflow->statusText())->toContain('unresolved_questions')
            ->and(factoryLog($root))->toBe("ignore local files\ninit");
    } finally {
        factoryRemove($root);
    }
});

it('stops analyze when every finding uses a discarded rule', function () {
    $root = factoryRoot();
    $repairs = 0;

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$repairs): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'ANALYZE_FIX')) {
                $repairs++;

                return '{"status":"done","summary":"repaired","assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"changes_requested","issues":[{"file":"specs/001-demo/plan.md","line":71,"severity":"high","rule":"inconsistency","problem":"companies has no company_id","fix":"scope by id"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        expect(fn () => factoryWorkflow($root, $agent, null, 4)->start('Record a gate pass'))
            ->toThrow(FactoryStop::class, 'inconsistency specs/001-demo/plan.md:71: companies has no company_id');

        expect($repairs)->toBe(0)
            ->and(is_file($root.'/recorded.txt'))->toBeFalse();
    } finally {
        factoryRemove($root);
    }
});

it('repairs a known analyze finding and omits a discarded rule from the fix', function () {
    $root = factoryRoot();
    $fixPrompt = '';

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$fixPrompt): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'ANALYZE_FIX')) {
                $fixPrompt = $prompt;
                $tasks = $cwd.'/'.$dir.'/tasks.md';
                file_put_contents($tasks, ((string) file_get_contents($tasks))."\nFixed.\n");

                return '{"status":"done","summary":"repaired","assumptions":[]}';
            }

            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                expect($prompt)->toContain('Any other rule is discarded and is not repaired.');
                $tasks = $cwd.'/'.$dir.'/tasks.md';
                if (is_file($tasks) && str_contains((string) file_get_contents($tasks), 'Fixed.')) {
                    return '{"verdict":"approve","issues":[],"assumptions":[]}';
                }

                return '{"verdict":"changes_requested","issues":[{"file":"specs/001-demo/tasks.md","line":1,"severity":"high","rule":"constitution:I","problem":"layer","fix":"split it"},{"file":"specs/001-demo/plan.md","line":71,"severity":"high","rule":"inconsistency","problem":"companies has no company_id","fix":"scope by id"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1)->start('Record a gate pass');

        expect($fixPrompt)->toContain('layer')
            ->and($fixPrompt)->not->toContain('companies has no company_id');
    } finally {
        factoryRemove($root);
    }
});

it('stops when analyze requests a real change', function () {
    $root = factoryRoot();

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"changes_requested","issues":[{"rule":"constitution:I","problem":"layer"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        expect(fn () => factoryWorkflow($root, $agent)->start('Record a gate pass'))
            ->toThrow(FactoryStop::class, 'Analyze requested changes');

        expect(is_file($root.'/recorded.txt'))->toBeFalse()
            ->and(is_file($root.'/specs/001-demo/tasks.md'))->toBeTrue()
            ->and(factoryLog($root))->toBe("ignore local files\ninit");
    } finally {
        factoryRemove($root);
    }
});

it('repairs a critical analyze finding and continues', function () {
    $root = factoryRoot();
    $analyzed = 0;

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$analyzed): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'ANALYZE_FIX')) {
                $tasks = $cwd.'/'.$dir.'/tasks.md';
                file_put_contents($tasks, ((string) file_get_contents($tasks))."\nFixed.\n");

                return '{"status":"done","summary":"repaired","assumptions":[]}';
            }

            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                $analyzed++;
                if ($analyzed === 1) {
                    return '{"verdict":"changes_requested","issues":[{"file":"specs/001-demo/tasks.md","line":1,"severity":"critical","rule":"constitution:Definition of Done","problem":"split the assertion","fix":"move it"}],"assumptions":[]}';
                }

                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1)->start('Record a gate pass');

        expect($analyzed)->toBe(2)
            ->and(factoryLog($root))->toBe("implement specs/001-demo\nspec specs/001-demo\nignore local files\ninit")
            ->and(file_get_contents($root.'/specs/001-demo/tasks.md'))->toContain('Fixed.');
    } finally {
        factoryRemove($root);
    }
});

it('keeps going when the spec author asks a question about an analyze finding', function () {
    $root = factoryRoot();
    $repairs = 0;

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$repairs): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'ANALYZE_FIX')) {
                $repairs++;
                if ($repairs === 1) {
                    return '{"status":"spec_gap","summary":"Which file?","assumptions":[]}';
                }

                $tasks = $cwd.'/'.$dir.'/tasks.md';
                file_put_contents($tasks, ((string) file_get_contents($tasks))."\nFixed.\n");

                return '{"status":"done","summary":"repaired","assumptions":[]}';
            }

            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                $tasks = $cwd.'/'.$dir.'/tasks.md';
                if (is_file($tasks) && str_contains((string) file_get_contents($tasks), 'Fixed.')) {
                    return '{"verdict":"approve","issues":[],"assumptions":[]}';
                }

                return '{"verdict":"changes_requested","issues":[{"file":"specs/001-demo/tasks.md","line":1,"severity":"high","rule":"constitution:Definition of Done","problem":"split the assertion","fix":"move it"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        $workflow = factoryWorkflow($root, $agent, null, 1);
        $workflow->start('Record a gate pass');

        expect($repairs)->toBe(2)
            ->and($workflow->statusText())->toBe('specs/001-demo done')
            ->and(file_get_contents($root.'/specs/001-demo/tasks.md'))->toContain('Fixed.');
    } finally {
        factoryRemove($root);
    }
});

it('continues when analyze reports only a low finding', function () {
    $root = factoryRoot();
    $repaired = false;

    try {
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$repaired): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'ANALYZE_FIX')) {
                $repaired = true;

                return '{"status":"done","summary":"repaired","assumptions":[]}';
            }

            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"changes_requested","issues":[{"severity":"low","rule":"constitution:Conventions","problem":"wording","fix":"rephrase"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        $workflow = factoryWorkflow($root, $agent);
        $workflow->start('Record a gate pass');

        expect($repaired)->toBeFalse()
            ->and($workflow->statusText())->toBe('specs/001-demo done');
    } finally {
        factoryRemove($root);
    }
});

it('refuses a second run while the current process holds the lock', function () {
    $root = factoryRoot();

    try {
        $dir = 'specs/001-demo';
        mkdir($root.'/'.$dir.'/.factory', 0777, true);
        file_put_contents($root.'/.specify/feature.json', json_encode(['feature_directory' => $dir]));
        $state = new FactoryState($dir, '001-demo', 'Record a gate pass', 'specify', null, [
            'pid' => getmypid() ?: 1,
            'at' => gmdate('c'),
        ], 0, []);
        file_put_contents(
            $root.'/'.$dir.'/.factory/state.json',
            json_encode($state->toArray(), JSON_PRETTY_PRINT)."\n",
        );

        $workflow = factoryWorkflow($root, factoryAgent(fn (): string => '{"status":"done","summary":"ok","assumptions":[]}'));

        expect(fn () => $workflow->resume())->toThrow(FactoryStop::class, 'locked');

        $workflow->unlock();

        $decoded = json_decode((string) file_get_contents($root.'/'.$dir.'/.factory/state.json'), true);
        expect(is_array($decoded) ? $decoded['lock'] : 'invalid')->toBeNull();
    } finally {
        factoryRemove($root);
    }
});

it('commits a checkout edit with the spec once analyze accepts it', function () {
    $root = factoryRoot();
    $seen = '';

    try {
        $reject = factoryAgent(function (string $cwd, string $prompt): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"changes_requested","issues":[{"rule":"constitution:I","problem":"layer"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        expect(fn () => factoryWorkflow($root, $reject)->start('Record a gate pass'))
            ->toThrow(FactoryStop::class, 'Analyze requested changes');

        $tasks = $root.'/specs/001-demo/tasks.md';
        file_put_contents($tasks, ((string) file_get_contents($tasks))."\nFixed.\n");

        $accept = factoryAgent(function (string $cwd, string $prompt) use (&$seen): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                $seen = (string) file_get_contents($cwd.'/'.$dir.'/tasks.md');

                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $accept)->resume();

        expect($seen)->toContain('Fixed.')
            ->and(factoryLog($root))->toBe("implement specs/001-demo\nspec specs/001-demo\nignore local files\ninit")
            ->and(file_get_contents($root.'/specs/001-demo/tasks.md'))->toContain('Fixed.');
    } finally {
        factoryRemove($root);
    }
});

it('resumes the author chat and uses the editor model from plan onward', function () {
    $root = factoryRoot();

    try {
        $agent = new class implements AgentClient
        {
            /** @var list<array{model: string, chat: string|null, prompt: string}> */
            public array $seen = [];

            public int $analyzed = 0;

            public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
            {
                $this->seen[] = ['model' => $model, 'chat' => $chatId, 'prompt' => $prompt];
                $dir = 'specs/001-demo';

                if (str_contains($prompt, 'ANALYZE_FIX')) {
                    return AgentReply::fromStream('{"status":"done","summary":"repaired","assumptions":[],"session_id":"editor-chat"}');
                }

                if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                    file_put_contents($cwd.'/recorded.txt', "pass\n");

                    return AgentReply::fromStream('{"status":"done","summary":"recorded","assumptions":[],"session_id":"impl"}');
                }

                if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                    return AgentReply::fromStream('{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}');
                }

                if (str_contains($prompt, 'speckit-analyze')) {
                    $this->analyzed++;
                    if ($this->analyzed === 1) {
                        return AgentReply::fromStream('{"verdict":"changes_requested","issues":[{"severity":"medium","rule":"constitution:IV","problem":"split the assertion","fix":"move it"}],"assumptions":[]}');
                    }

                    return AgentReply::fromStream('{"verdict":"approve","issues":[],"assumptions":[]}');
                }

                if (str_contains($prompt, 'speckit-specify')) {
                    file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
                }

                if (str_contains($prompt, 'speckit-plan')) {
                    file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
                }

                if (str_contains($prompt, 'speckit-tasks')) {
                    file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
                }

                $session = str_contains($prompt, 'speckit-plan') || str_contains($prompt, 'speckit-tasks')
                    ? 'editor-chat'
                    : 'opus-chat';

                return AgentReply::fromStream('{"status":"done","summary":"ok","assumptions":[],"session_id":"'.$session.'"}');
            }
        };

        factoryWorkflow($root, $agent)->start('Record a gate pass');

        $call = function (string $needle) use ($agent): array {
            foreach ($agent->seen as $seen) {
                if (str_contains($seen['prompt'], $needle)) {
                    return $seen;
                }
            }

            throw new RuntimeException('Missing call '.$needle);
        };

        expect($call('speckit-specify')['model'])->toBe('spec-model')
            ->and($call('speckit-specify')['chat'])->toBeNull()
            ->and($call('speckit-clarify')['model'])->toBe('spec-model')
            ->and($call('speckit-clarify')['chat'])->toBe('opus-chat')
            ->and($call('speckit-plan')['model'])->toBe('editor-model')
            ->and($call('speckit-plan')['chat'])->toBeNull()
            ->and($call('speckit-tasks')['model'])->toBe('editor-model')
            ->and($call('speckit-tasks')['chat'])->toBe('editor-chat')
            ->and($call('ANALYZE_FIX')['model'])->toBe('editor-model')
            ->and($call('ANALYZE_FIX')['chat'])->toBe('editor-chat');
    } finally {
        factoryRemove($root);
    }
});

it('runs feature tests and coding on different models', function () {
    $root = factoryRoot();

    try {
        $agent = new class implements AgentClient
        {
            /** @var list<array{model: string, prompt: string}> */
            public array $seen = [];

            public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
            {
                $this->seen[] = ['model' => $model, 'prompt' => $prompt];
                $dir = 'specs/001-demo';

                if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                    file_put_contents($cwd.'/recorded.txt', "pass\n");
                }

                if (str_contains($prompt, 'speckit-specify')) {
                    file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
                }

                if (str_contains($prompt, 'speckit-plan')) {
                    file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
                }

                if (str_contains($prompt, 'speckit-tasks')) {
                    file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
                }

                if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW') || str_contains($prompt, 'speckit-analyze')) {
                    return AgentReply::fromStream('{"verdict":"approve","issues":[],"assumptions":[],"scenarios":[{"id":"1","result":"pass"}]}');
                }

                return AgentReply::fromStream('{"status":"done","summary":"ok","assumptions":[]}');
            }
        };

        factoryWorkflow($root, $agent)->start('Record a gate pass');

        $steps = [];
        foreach ($agent->seen as $call) {
            if (str_contains($call['prompt'], 'FEATURE_TESTS') || str_contains($call['prompt'], 'IMPLEMENT_TASK')) {
                $steps[] = $call['model'];
            }
        }

        expect($steps)->toBe(['feature-model', 'implement-model']);
    } finally {
        factoryRemove($root);
    }
});

function factoryRoot(): string
{
    $root = sys_get_temp_dir().'/access-factory-'.bin2hex(random_bytes(4));
    mkdir($root);
    putenv('GIT_AUTHOR_NAME=Factory Test');
    putenv('GIT_AUTHOR_EMAIL=factory@example.com');
    putenv('GIT_COMMITTER_NAME=Factory Test');
    putenv('GIT_COMMITTER_EMAIL=factory@example.com');
    factoryGit($root, ['init', '-b', 'main']);
    factoryGit($root, ['commit', '--allow-empty', '-m', 'init']);
    file_put_contents($root.'/.gitignore', "/.worktrees/\n/factory/runs/\n");
    mkdir($root.'/.specify', 0777, true);
    file_put_contents($root.'/.specify/.gitignore', "feature.json\n");
    mkdir($root.'/factory/prompts', 0777, true);
    mkdir($root.'/.cursor', 0777, true);
    file_put_contents($root.'/factory/prompts/feature_tester.md', "FEATURE_TESTS\n");
    file_put_contents($root.'/factory/prompts/implementer.md', "IMPLEMENT_TASK\n");
    file_put_contents($root.'/factory/prompts/quality_reviewer.md', "QUALITY_REVIEW\n");
    file_put_contents($root.'/factory/prompts/functional_reviewer.md', "FUNCTIONAL_REVIEW\n");
    file_put_contents($root.'/.cursor/cli.json', '{"permissions":{"allow":[]}}');
    factoryGit($root, ['add', '--', '.gitignore', '.specify/.gitignore']);
    factoryGit($root, ['commit', '-m', 'ignore local files']);

    return $root;
}

it('continues a scaffold whose feature pointer is gitignored', function () {
    $root = factoryRoot();

    try {
        $dir = 'specs/002-demo';
        mkdir($root.'/'.$dir, 0777, true);
        file_put_contents($root.'/'.$dir.'/spec.md', "draft\n");
        file_put_contents($root.'/.specify/feature.json', json_encode(['feature_directory' => $dir])."\n");
        factoryGit($root, ['checkout', '-b', '002-demo']);

        $workflow = factoryWorkflow($root, factoryAgent(function (string $cwd, string $prompt): string {
            $dir = 'specs/002-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        }), new class implements FeatureScaffolder
        {
            public function create(string $root, string $description): array
            {
                throw new RuntimeException('Scaffold already exists.');
            }
        });

        $workflow->start('Record a gate pass');

        expect($workflow->statusText())->toBe('specs/002-demo done');
    } finally {
        factoryRemove($root);
    }
});

it('starts the next feature when the active pointer is done', function () {
    $root = factoryRoot();

    try {
        $dir = 'specs/001-demo';
        mkdir($root.'/'.$dir.'/.factory', 0777, true);
        file_put_contents($root.'/'.$dir.'/spec.md', "User Story 1\n\nDone feature.\n");
        file_put_contents($root.'/'.$dir.'/.factory/state.json', json_encode([
            'feature_dir' => $dir,
            'branch' => '001-demo',
            'description' => 'Done feature.',
            'next' => 'done',
            'stop' => null,
            'lock' => null,
            'converge_rounds' => 0,
            'tasks' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/.specify/feature.json', json_encode(['feature_directory' => $dir], JSON_THROW_ON_ERROR)."\n");

        $created = null;
        $scaffolder = new class($created) implements FeatureScaffolder
        {
            public function __construct(private mixed &$created) {}

            public function create(string $root, string $description): array
            {
                $this->created = ['branch' => '002-companies', 'dir' => 'specs/002-companies'];
                mkdir($root.'/specs/002-companies', 0777, true);
                file_put_contents($root.'/specs/002-companies/spec.md', "template\n");

                return $this->created;
            }
        };

        $agent = factoryAgent(fn (): string => '{"status":"spec_gap","summary":"Need detail","assumptions":[]}');

        factoryWorkflow($root, $agent, $scaffolder)->start('Company management for a CRM');

        expect($created)->toBe(['branch' => '002-companies', 'dir' => 'specs/002-companies'])
            ->and(is_file($root.'/specs/002-companies/.factory/state.json'))->toBeTrue()
            ->and(json_decode((string) file_get_contents($root.'/specs/002-companies/.factory/state.json'), true)['feature_dir'] ?? null)
            ->toBe('specs/002-companies');
    } finally {
        factoryRemove($root);
    }
});

it('blocks a second run while the active feature is still in progress', function () {
    $root = factoryRoot();

    try {
        $dir = 'specs/001-demo';
        mkdir($root.'/'.$dir.'/.factory', 0777, true);
        file_put_contents($root.'/'.$dir.'/spec.md', "User Story 1\n\nIn progress.\n");
        file_put_contents($root.'/'.$dir.'/.factory/state.json', json_encode([
            'feature_dir' => $dir,
            'branch' => '001-demo',
            'description' => 'In progress.',
            'next' => 'implement',
            'stop' => null,
            'lock' => null,
            'converge_rounds' => 0,
            'tasks' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/.specify/feature.json', json_encode(['feature_directory' => $dir], JSON_THROW_ON_ERROR)."\n");

        expect(fn () => factoryWorkflow($root, factoryAgent(fn (): string => '{"status":"done","summary":"ok","assumptions":[]}'))
            ->start('Another brief'))
            ->toThrow(function (FactoryStop $stop): bool {
                return $stop->reason === 'already_started';
            });
    } finally {
        factoryRemove($root);
    }
});

it('links node modules and the env file without installing vendor', function () {
    $root = factoryRoot();

    try {
        mkdir($root.'/node_modules', 0777, true);
        file_put_contents($root.'/.env', "APP_KEY=\n");
        $worktree = $root.'/.worktrees/probe';
        mkdir($worktree, 0777, true);
        (new GitRepo($root))->linkDependencies($worktree);

        expect(is_link($worktree.'/node_modules'))->toBeTrue()
            ->and(is_link($worktree.'/.env'))->toBeTrue()
            ->and(file_exists($worktree.'/vendor'))->toBeFalse();
    } finally {
        factoryRemove($root);
    }
});

it('installs a vendor directory inside the worktree', function () {
    $root = factoryRoot();

    try {
        mkdir($root.'/vendor', 0777, true);
        file_put_contents($root.'/vendor/marker.txt', "checkout\n");
        mkdir($root.'/node_modules', 0777, true);
        file_put_contents($root.'/.env', "APP_KEY=\n");
        $worktree = $root.'/.worktrees/probe';
        mkdir($worktree, 0777, true);
        file_put_contents($worktree.'/composer.json', <<<'JSON'
        {
        "name": "acme/factory-worktree",
        "require": {}
        }
        JSON);
        file_put_contents($worktree.'/composer.lock', <<<'LOCK'
        {
            "_readme": [
                "This file locks the dependencies of your project to a known state",
                "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
                "This file is @generated automatically"
            ],
            "content-hash": "84ecf9e1372dcd991f7b35455300eb1c",
            "packages": [],
            "packages-dev": [],
            "aliases": [],
            "minimum-stability": "stable",
            "stability-flags": {},
            "prefer-stable": false,
            "prefer-lowest": false,
            "platform": {},
            "platform-dev": {},
            "plugin-api-version": "2.9.0"
        }
        LOCK);
        symlink($root.'/vendor', $worktree.'/vendor');
        (new GitRepo($root))->linkDependencies($worktree);

        $autoload = realpath($worktree.'/vendor/autoload.php');

        expect(is_link($worktree.'/vendor'))->toBeFalse()
            ->and($autoload)->toBeString()
            ->and(str_starts_with((string) $autoload, (string) realpath($worktree)))->toBeTrue()
            ->and(file_get_contents($root.'/vendor/marker.txt'))->toBe("checkout\n")
            ->and(is_link($worktree.'/node_modules'))->toBeTrue()
            ->and(is_link($worktree.'/.env'))->toBeTrue();
    } finally {
        factoryRemove($root);
    }
});

it('gives the next implementer attempt the verify log', function () {
    $root = factoryRoot();
    $flag = $root.'/gate.flag';
    $verify = 'sh -c '.escapeshellarg('if [ -f '.escapeshellarg($flag).' ]; then exit 0; fi; echo GATE_FAILURE_MARKER; touch '.escapeshellarg($flag).'; exit 1');

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1, $verify)->start('Record a gate pass');

        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[0])->not->toContain('GATE_FAILURE_MARKER')
            ->and($implementPrompts[1])->toContain('GATE_FAILURE_MARKER')
            ->and($implementPrompts[1])->toContain('failed make verify');
    } finally {
        factoryRemove($root);
    }
});

it('sends only the failure when the implementer chat resumes on a committed attempt', function () {
    $root = factoryRoot();
    $flag = $root.'/gate.flag';
    $verify = 'sh -c '.escapeshellarg('if [ -f '.escapeshellarg($flag).' ]; then exit 0; fi; echo GATE_FAILURE_MARKER; touch '.escapeshellarg($flag).'; exit 1');

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK') || str_contains($prompt, 'Continue from that code')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return factoryResultStream('{"status":"done","summary":"recorded","assumptions":[]}');
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1, $verify)->start('Record a gate pass');

        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[0])->toContain('IMPLEMENT_TASK')
            ->and($implementPrompts[0])->toContain('A pass is recorded.')
            ->and($implementPrompts[1])->toContain('Continue from that code')
            ->and($implementPrompts[1])->toContain('GATE_FAILURE_MARKER')
            ->and($implementPrompts[1])->toContain('Do not commit')
            ->and($implementPrompts[1])->not->toContain('IMPLEMENT_TASK')
            ->and($implementPrompts[1])->not->toContain('A pass is recorded.');
    } finally {
        factoryRemove($root);
    }
});

it('sends the full task prompt when the previous implementer attempt was discarded', function () {
    $root = factoryRoot();
    $attempts = 0;

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts, &$attempts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK') || str_contains($prompt, 'Continue from that code')) {
                $attempts++;
                $implementPrompts[] = $prompt;
                if ($attempts === 1) {
                    return factoryResultStream('{"status":"failed","summary":"nope","assumptions":[]}');
                }

                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return factoryResultStream('{"status":"done","summary":"recorded","assumptions":[]}');
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1)->start('Record a gate pass');

        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[1])->toContain('IMPLEMENT_TASK')
            ->and($implementPrompts[1])->toContain('A pass is recorded.')
            ->and($implementPrompts[1])->not->toContain('Continue from that code');
    } finally {
        factoryRemove($root);
    }
});

it('gives the next implementer attempt the reviewer issues', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $qualityReviews = 0;
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts, &$qualityReviews): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW')) {
                $qualityReviews++;
                if ($qualityReviews === 1) {
                    return '{"verdict":"changes_requested","issues":[{"file":"config/sanctum.php","line":2,"severity":"high","rule":"constitution:II","problem":"missing strict types","fix":"add declare(strict_types=1)"}],"assumptions":[]}';
                }

                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1)->start('Record a gate pass');

        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[0])->not->toContain('missing strict types')
            ->and($implementPrompts[1])->toContain('missing strict types')
            ->and($implementPrompts[1])->toContain('add declare(strict_types=1)')
            ->and($implementPrompts[1])->toContain('make verify had passed')
            ->and($implementPrompts[1])->not->toContain('failed make verify');
    } finally {
        factoryRemove($root);
    }
});

it('runs the functional reviewer after a quality rejection and keeps both findings', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $qualityReviews = 0;
        $functionalReviews = 0;
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts, &$qualityReviews, &$functionalReviews): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW')) {
                $qualityReviews++;
                if ($qualityReviews === 1) {
                    return '{"verdict":"changes_requested","issues":[{"file":"config/sanctum.php","line":2,"severity":"high","rule":"constitution:II","problem":"missing strict types","fix":"add declare(strict_types=1)"}],"assumptions":[]}';
                }

                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                $functionalReviews++;
                if ($functionalReviews === 1) {
                    return '{"verdict":"changes_requested","issues":[],"scenarios":[{"id":"1","result":"missing_test","problem":"guest reset stays isolated"}]}';
                }

                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1)->start('Record a gate pass');

        expect($functionalReviews)->toBeGreaterThan(0)
            ->and($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[1])->toContain('missing strict types')
            ->and($implementPrompts[1])->toContain('guest reset stays isolated')
            ->and($implementPrompts[1])->toContain('quality reviewer rejected')
            ->and($implementPrompts[1])->toContain('functional reviewer rejected');
    } finally {
        factoryRemove($root);
    }
});

it('keeps reviewer issues for the first attempt after resume', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW')) {
                return '{"verdict":"changes_requested","issues":[{"file":"config/sanctum.php","line":2,"severity":"high","rule":"constitution:II","problem":"missing strict types","fix":"add declare(strict_types=1)"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        $workflow = factoryWorkflow($root, $agent);
        expect(fn () => $workflow->start('Record a gate pass'))->toThrow(FactoryStop::class, 'T001 used 1 attempts.');
        expect(fn () => $workflow->resume())->toThrow(FactoryStop::class, 'T001 used 1 attempts.');
        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[1])->toContain('missing strict types')
            ->and($implementPrompts[1])->not->toContain('failed make verify');
    } finally {
        factoryRemove($root);
    }
});

it('gives the next implementer attempt the assumptions that blocked approval', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $implementations = 0;
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts, &$implementations): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                $implementations++;
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");
                if ($implementations === 1) {
                    return '{"status":"done","summary":"recorded","assumptions":["guessed a column"]}';
                }

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 1)->start('Record a gate pass');

        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[0])->not->toContain('guessed a column')
            ->and($implementPrompts[1])->toContain('guessed a column')
            ->and($implementPrompts[1])->toContain('not approved because assumptions')
            ->and($implementPrompts[1])->not->toContain('failed make verify');
    } finally {
        factoryRemove($root);
    }
});

it('lets balanced review mode pass with implementer assumptions', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK') || str_contains($prompt, 'Continue from that code')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":["chose saving hooks for FK refusal"]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                expect($prompt)->toContain('Review mode: balanced');

                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 0, 'true', 'balanced')->start('Record a gate pass');

        $tasks = (string) file_get_contents($root.'/specs/001-demo/tasks.md');
        expect($implementPrompts)->toHaveCount(1)
            ->and($implementPrompts[0])->toContain('Review mode: balanced')
            ->and($tasks)->toContain('- [x] T001');
    } finally {
        factoryRemove($root);
    }
});

it('lets soft review mode ignore medium findings and missing tests', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK') || str_contains($prompt, 'Continue from that code')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW')) {
                return '{"verdict":"changes_requested","issues":[{"severity":"high","rule":"constitution:III","problem":"test blanket","fix":"remove it"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"changes_requested","issues":[],"scenarios":[{"id":"1","result":"missing_test"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent, null, 0, 'true', 'soft')->start('Record a gate pass');

        $tasks = (string) file_get_contents($root.'/specs/001-demo/tasks.md');
        expect($implementPrompts)->toHaveCount(1)
            ->and($tasks)->toContain('- [x] T001');
    } finally {
        factoryRemove($root);
    }
});

it('rejects soft review mode on Prohibitions', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK') || str_contains($prompt, 'Continue from that code')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW')) {
                return '{"verdict":"changes_requested","issues":[{"severity":"medium","rule":"constitution:Prohibitions","problem":"new dependency","fix":"remove it"}],"assumptions":[]}';
            }

            if (str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', "## Phase 1: Setup\n\n- [ ] T001 Record a pass\n");
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        expect(fn () => factoryWorkflow($root, $agent, null, 0, 'true', 'soft')->start('Record a gate pass'))
            ->toThrow(FactoryStop::class, 'T001 used 1 attempts.');
        expect($implementPrompts)->toHaveCount(1);
    } finally {
        factoryRemove($root);
    }
});

it('implements five tasks in one wave and leaves the sixth for the next', function () {
    $root = factoryRoot();

    try {
        $implementPrompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$implementPrompts): string {
            $dir = 'specs/001-demo';
            if (str_contains($prompt, 'IMPLEMENT_TASK')) {
                $implementPrompts[] = $prompt;
                file_put_contents($cwd.'/recorded.txt', "pass\n");

                return '{"status":"done","summary":"recorded","assumptions":[]}';
            }

            if (str_contains($prompt, 'QUALITY_REVIEW') || str_contains($prompt, 'FUNCTIONAL_REVIEW')) {
                return '{"verdict":"approve","issues":[],"scenarios":[{"id":"1","result":"pass"}]}';
            }

            if (str_contains($prompt, 'speckit-analyze')) {
                return '{"verdict":"approve","issues":[],"assumptions":[]}';
            }

            if (str_contains($prompt, 'speckit-specify')) {
                file_put_contents($cwd.'/'.$dir.'/spec.md', "User Story 1\n\nA pass is recorded.\n");
            }

            if (str_contains($prompt, 'speckit-plan')) {
                file_put_contents($cwd.'/'.$dir.'/plan.md', "plan\n");
            }

            if (str_contains($prompt, 'speckit-tasks')) {
                file_put_contents($cwd.'/'.$dir.'/tasks.md', <<<'MD'
                ## Phase 1: Setup

                - [ ] T001 Record a pass
                - [ ] T002 Record a pass (depends on T001)
                - [ ] T003 Record a pass (depends on T002)
                - [ ] T004 Record a pass (depends on T003)
                - [ ] T005 Record a pass (depends on T004)
                - [ ] T006 Record a pass (depends on T005)
                MD);
            }

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });

        factoryWorkflow($root, $agent)->start('Record a gate pass');

        $tasks = (string) file_get_contents($root.'/specs/001-demo/tasks.md');
        expect($implementPrompts)->toHaveCount(2)
            ->and($implementPrompts[0])->toContain('T001')
            ->and($implementPrompts[0])->toContain('T005')
            ->and($implementPrompts[0])->not->toContain('T006')
            ->and($implementPrompts[1])->toContain('T006')
            ->and($tasks)->toContain('- [x] T001')
            ->and($tasks)->toContain('- [x] T006');
    } finally {
        factoryRemove($root);
    }
});

function factoryWorkflow(string $root, AgentClient $agent, ?FeatureScaffolder $scaffolder = null, int $retries = 0, string $verify = 'true', string $reviewMode = 'strict', string $mode = 'full', ?FactoryLog $log = null): Workflow
{
    return new Workflow(
        $root,
        new Contract([
            'verify_command' => $verify,
            'max_retries' => $retries,
            'max_converge_rounds' => 2,
            'agent_timeout_seconds' => 30,
            'mode' => $mode,
            'implement_loop' => [
                'review_mode' => $reviewMode,
            ],
            'models' => [
                'spec_author' => 'spec-model',
                'spec_editor' => 'editor-model',
                'feature_tester' => 'feature-model',
                'implementer' => 'implement-model',
                'reviewer' => 'review-model',
            ],
        ]),
        new GitRepo($root),
        $agent,
        $scaffolder ?? new class implements FeatureScaffolder
        {
            public function create(string $root, string $description): array
            {
                $dir = 'specs/001-demo';
                mkdir($root.'/'.$dir, 0777, true);
                if (! is_dir($root.'/.specify')) {
                    mkdir($root.'/.specify', 0777, true);
                }
                file_put_contents($root.'/'.$dir.'/spec.md', "draft\n");
                file_put_contents($root.'/.specify/feature.json', json_encode([
                    'feature_directory' => $dir,
                ])."\n");

                return ['branch' => '001-demo', 'dir' => $dir];
            }
        },
        $log,
    );
}

function factoryResultStream(string $payload): string
{
    $encoded = json_encode([
        'type' => 'result',
        'session_id' => 'chat-impl',
        'result' => $payload,
    ]);

    return is_string($encoded) ? $encoded : $payload;
}

function factoryAgent(Closure $reply): AgentClient
{
    return new class($reply) implements AgentClient
    {
        public function __construct(private Closure $reply) {}

        public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
        {
            return AgentReply::fromStream(($this->reply)($cwd, $prompt));
        }
    };
}

function factoryRemove(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $process = new Process(['rm', '-rf', $root]);
    $process->run();
}

/**
 * @param  list<string>  $arguments
 */
function factoryGit(string $root, array $arguments): void
{
    $process = new Process(['git', ...$arguments], $root);
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
    }
}

function factoryLog(string $root): string
{
    $process = new Process(['git', 'log', '--format=%s'], $root);
    $process->mustRun();

    return trim($process->getOutput());
}
