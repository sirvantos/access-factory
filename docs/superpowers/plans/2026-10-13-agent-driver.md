# Agent Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Select Cursor CLI or Claude Code from `factory/factory.yaml` and let that driver own the process, the stream parser, and the worktree sandbox.

**Architecture:** `AgentDriver` extends `AgentClient`. `CursorDriver` and `ClaudeDriver` build the command, parse their own stream, and render one shared deny-path list. `Workflow` asks the driver for the sandbox file and refuses to continue a run whose stored driver differs from the live one. `AgentReply` only reads the factory JSON object the driver has already extracted.

**Tech Stack:** PHP 8.4, Pest, Symfony Process, Symfony Yaml.

**Spec:** `docs/superpowers/specs/2026-10-13-agent-driver-design.md`

## Global Constraints

- Legal `driver` values are `cursor` and `claude`. A missing key means `cursor`.
- Skills stay at `.cursor/skills/` for both drivers.
- Models stay in the existing `models` map and are passed through as-is.
- A run stores its driver on `state.json`. `continueRun` stops with `driver_mismatch` when that value differs from the live driver. `status`, `stale`, and `unlock` do not check.
- `DiffGuard` protects both `.cursor/` and `.claude/`.
- Both drivers use `agent_timeout_seconds` and stream every process buffer to `FactoryLog`.
- Tests must not spawn `cursor-agent` or `claude`. Assert commands with `command()` and exit policy with `outcome()`.
- `Workflow`'s property stays `$agent`. Only its type becomes `AgentDriver`.
- Run `vendor/bin/pint --dirty` before each commit. Commit messages use `feat:`.

---

### Task 1: Shared deny paths

**Files:**
- Create: `src/SandboxPaths.php`
- Test: `tests/Unit/SandboxPathsTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `Access\Factory\SandboxPaths::forRole(string $role): list<string>`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SandboxPathsTest.php`:

```php
<?php

declare(strict_types=1);

use Access\Factory\SandboxPaths;

it('denies the tooling paths for every role', function () {
    expect(SandboxPaths::forRole('spec_author'))->toBe([
        '.specify/**',
        '.cursor/**',
        'factory/**',
        '.github/**',
        '.gitlab-ci.yml',
        '.gitlab/**',
        'Makefile',
        'phpstan.neon',
        'deptrac.php',
        'phpunit.xml',
    ])->and(SandboxPaths::forRole('converge'))->toBe(SandboxPaths::forRole('spec_author'));
});

it('adds the spec files for implementer and feature tester', function () {
    $extra = [
        'specs/**/spec.md',
        'specs/**/plan.md',
        'specs/**/tasks.md',
        'specs/**/research.md',
        'specs/**/data-model.md',
        'specs/**/quickstart.md',
        'specs/**/constraints.md',
        'specs/**/contracts/**',
        'specs/**/checklists/**',
    ];

    expect(SandboxPaths::forRole('implementer'))->toBe([...SandboxPaths::forRole('spec_author'), ...$extra])
        ->and(SandboxPaths::forRole('feature_tester'))->toBe(SandboxPaths::forRole('implementer'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/SandboxPathsTest.php`

Expected: FAIL with `SandboxPaths` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `src/SandboxPaths.php`:

```php
<?php

declare(strict_types=1);

namespace Access\Factory;

final class SandboxPaths
{
    /**
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        $paths = [
            '.specify/**',
            '.cursor/**',
            'factory/**',
            '.github/**',
            '.gitlab-ci.yml',
            '.gitlab/**',
            'Makefile',
            'phpstan.neon',
            'deptrac.php',
            'phpunit.xml',
        ];

        if ($role === 'implementer' || $role === 'feature_tester') {
            array_push(
                $paths,
                'specs/**/spec.md',
                'specs/**/plan.md',
                'specs/**/tasks.md',
                'specs/**/research.md',
                'specs/**/data-model.md',
                'specs/**/quickstart.md',
                'specs/**/constraints.md',
                'specs/**/contracts/**',
                'specs/**/checklists/**',
            );
        }

        return $paths;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Unit/SandboxPathsTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/SandboxPaths.php tests/Unit/SandboxPathsTest.php
git commit -m "$(cat <<'EOF'
feat: share the sandbox deny paths

EOF
)"
```

---

### Task 2: Driver key on the contract

**Files:**
- Modify: `src/Contract.php`
- Modify: `tests/Unit/ContractTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `Contract::driver(): string` returning `cursor` or `claude`. Throws `RuntimeException` with message `driver must be cursor or claude.` for any other value, including a non-string. A missing key returns `cursor`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/ContractTest.php`:

```php
it('defaults a missing driver to cursor', function () {
    expect((new Contract([]))->driver())->toBe('cursor');
});

it('accepts cursor and claude', function () {
    expect((new Contract(['driver' => 'cursor']))->driver())->toBe('cursor')
        ->and((new Contract(['driver' => 'claude']))->driver())->toBe('claude');
});

it('rejects an unknown driver', function () {
    expect(fn () => (new Contract(['driver' => 'codex']))->driver())
        ->toThrow(RuntimeException::class, 'driver must be cursor or claude.');
});
```

Add `use RuntimeException;` if the file does not already import it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/ContractTest.php`

Expected: FAIL with `driver` not found on `Contract`.

- [ ] **Step 3: Write the minimal implementation**

Add this method to `src/Contract.php` after `agentTimeout()`:

```php
public function driver(): string
{
    if (! array_key_exists('driver', $this->data)) {
        return 'cursor';
    }

    $driver = $this->data['driver'];
    if ($driver === 'cursor' || $driver === 'claude') {
        return $driver;
    }

    throw new RuntimeException('driver must be cursor or claude.');
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Unit/ContractTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Contract.php tests/Unit/ContractTest.php
git commit -m "$(cat <<'EOF'
feat: read the agent driver from the factory contract

EOF
)"
```

---

### Task 3: Factory JSON reply

**Files:**
- Modify: `src/AgentReply.php`
- Modify: `tests/Unit/AgentReplyTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `AgentReply::fromContractText(string $text, ?string $chatId): AgentReply`. It trims `$text`, finds the last JSON object that contains `status` or `verdict`, and sets `status` from that string. Missing both leaves `status` as `unknown` and `payload` as `[]`. `$chatId` is stored as given. `fromStream()` stays until Task 4.

- [ ] **Step 1: Write the failing test**

In `tests/Unit/AgentReplyTest.php`, add:

```php
it('reads a factory object the driver already extracted', function () {
    $reply = AgentReply::fromContractText(
        "working\n".'{"status":"done","summary":"ok","assumptions":[]}',
        'chat-1',
    );

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1')
        ->and($reply->blocksApproval())->toBeFalse();
});

it('returns unknown when the extracted text has no factory object', function () {
    $reply = AgentReply::fromContractText('still working', null);

    expect($reply->status)->toBe('unknown')
        ->and($reply->chatId)->toBeNull()
        ->and($reply->payload)->toBe([]);
});
```

Leave the existing `fromStream` tests in place.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/AgentReplyTest.php --filter="factory object the driver"`

Expected: FAIL with `fromContractText` not found.

- [ ] **Step 3: Write the minimal implementation**

Add this method to `src/AgentReply.php` immediately after `fromStream()`:

```php
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
```

Change the end of `fromStream()` so the event walk still builds `$text` and `$chatId`, then returns `self::fromContractText($text, $chatId)` instead of duplicating the payload read.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Unit/AgentReplyTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AgentReply.php tests/Unit/AgentReplyTest.php
git commit -m "$(cat <<'EOF'
feat: read factory replies from extracted agent text

EOF
)"
```

---

### Task 4: Cursor driver

**Files:**
- Create: `src/AgentDriver.php`
- Create: `src/AgentProcess.php`
- Create: `src/CursorDriver.php`
- Create: `tests/Unit/CursorDriverTest.php`
- Create: `tests/Unit/AgentProcessTest.php`
- Modify: `src/Contract.php` (`cliConfig()` delegates to the shared paths)
- Modify: `src/AgentReply.php` (delete `fromStream()`, `chatId()`, and `eventText()`)
- Modify: `bin/factory`
- Modify: `tests/Unit/AgentReplyTest.php`
- Modify: `tests/Unit/WorkflowTest.php`
- Delete: `src/CursorAgent.php`

**Interfaces:**
- Consumes: `SandboxPaths::forRole(string $role): list<string>`, `AgentReply::fromContractText(string $text, ?string $chatId): AgentReply`, `FactoryLog::stream(string $chunk): void`, `FactoryStop`
- Produces:
  - `interface AgentDriver extends AgentClient` with `name(): string`, `sandboxPath(): string`, `sandboxConfig(string $role): array`, `command(string $cwd, string $model, string $prompt, ?string $chatId): list<string>`, `parse(string $stream): AgentReply`, `outcome(int $exitCode, string $stdout, string $stderr): AgentReply`
  - `AgentProcess::run(array $command, string $cwd, int $timeoutSeconds, ?FactoryLog $log): array{exitCode: int, stdout: string, stderr: string}`
  - `CursorDriver` with `name()` `cursor` and `sandboxPath()` `.cursor/cli.json`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/CursorDriverTest.php`:

```php
<?php

declare(strict_types=1);

use Access\Factory\CursorDriver;
use Access\Factory\FactoryStop;

function cursorDriver(): CursorDriver
{
    return new CursorDriver(30);
}

it('builds the cursor command and resumes only with a chat id', function () {
    $driver = cursorDriver();

    expect($driver->command('/work', 'composer-2', 'do the thing', null))->toBe([
        'cursor-agent',
        '-p',
        '--output-format',
        'stream-json',
        '--model',
        'composer-2',
        '--trust',
        '--force',
        '--workspace',
        '/work',
        'do the thing',
    ])->and($driver->command('/work', 'composer-2', 'do the thing', ''))->toBe(
        $driver->command('/work', 'composer-2', 'do the thing', null),
    )->and($driver->command('/work', 'composer-2', 'do the thing', 'chat-9'))->toBe([
        'cursor-agent',
        '-p',
        '--output-format',
        'stream-json',
        '--model',
        'composer-2',
        '--trust',
        '--force',
        '--workspace',
        '/work',
        '--resume',
        'chat-9',
        'do the thing',
    ]);
});

it('renders cursor write rules', function () {
    $driver = cursorDriver();
    $author = $driver->sandboxConfig('spec_author');
    $implementer = $driver->sandboxConfig('implementer');

    expect($driver->name())->toBe('cursor')
        ->and($driver->sandboxPath())->toBe('.cursor/cli.json')
        ->and($author['permissions']['allow'])->toBe([])
        ->and($author['permissions']['deny'])->toContain('Write(.specify/**)')
        ->and($author['permissions']['deny'])->not->toContain('Write(specs/**/spec.md)')
        ->and($implementer['permissions']['deny'])->toContain('Write(specs/**/spec.md)');
});

it('parses a cursor stream into a factory reply', function () {
    $reply = cursorDriver()->parse(<<<'JSON'
    {"type":"system","session_id":"chat-1"}
    {"type":"assistant","message":{"content":[{"text":"working"}]}}
    {"type":"result","result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}
    JSON);

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1')
        ->and($reply->blocksApproval())->toBeFalse();
});

it('stops a failed cursor process that produced no factory object', function () {
    $driver = cursorDriver();

    expect(fn () => $driver->outcome(1, '', 'cursor broke'))
        ->toThrow(FactoryStop::class, 'cursor broke');

    expect(fn () => $driver->outcome(1, 'still working', ''))
        ->toThrow(FactoryStop::class, 'still working');
});

it('keeps a factory object from a non-zero cursor exit', function () {
    $reply = cursorDriver()->outcome(1, '{"status":"done","summary":"ok","assumptions":[]}', 'noise');

    expect($reply->status)->toBe('done');
});

it('returns unknown for a zero cursor exit with no factory object', function () {
    $reply = cursorDriver()->outcome(0, 'still working', '');

    expect($reply->status)->toBe('unknown');
});
```

Create `tests/Unit/AgentProcessTest.php`:

```php
<?php

declare(strict_types=1);

use Access\Factory\AgentProcess;
use Access\Factory\FactoryLog;

it('streams every process buffer to the log', function () {
    $root = sys_get_temp_dir().'/access-factory-process-'.bin2hex(random_bytes(4));
    mkdir($root);
    $seen = '';
    $log = new FactoryLog($root, true, function (string $chunk) use (&$seen): void {
        $seen .= $chunk;
    });

    try {
        $result = AgentProcess::run([PHP_BINARY, '-r', 'echo "hello";'], $root, 10, $log);

        expect($result['exitCode'])->toBe(0)
            ->and($result['stdout'])->toBe('hello')
            ->and($seen)->toBe('hello');
    } finally {
        $process = new Symfony\Component\Process\Process(['rm', '-rf', $root]);
        $process->run();
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/CursorDriverTest.php tests/Unit/AgentProcessTest.php`

Expected: FAIL with `CursorDriver` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `src/AgentDriver.php`:

```php
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
```

Create `src/AgentProcess.php`:

```php
<?php

declare(strict_types=1);

namespace Access\Factory;

use Symfony\Component\Process\Process;

final class AgentProcess
{
    /**
     * @param  list<string>  $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(array $command, string $cwd, int $timeoutSeconds, ?FactoryLog $log): array
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeoutSeconds);
        $stdout = '';
        $process->run(function (string $type, string $buffer) use (&$stdout, $log): void {
            $stdout .= $buffer;
            $log?->stream($buffer);
        });

        return [
            'exitCode' => $process->getExitCode() ?? 1,
            'stdout' => $stdout,
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
```

Create `src/CursorDriver.php`:

```php
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
```

Replace the body of `Contract::cliConfig()` with the same document `CursorDriver::sandboxConfig()` returns. Leave the method in place until Task 7:

```php
public function cliConfig(string $role): array
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
```

In `bin/factory`, construct `new CursorDriver($contract->agentTimeout(), $log)` instead of `CursorAgent`. Delete `src/CursorAgent.php`.

Delete `fromStream()`, `chatId()`, and `eventText()` from `AgentReply`.

Add this helper at the bottom of `tests/Unit/WorkflowTest.php`:

```php
function factoryReply(string $json): AgentReply
{
    $payload = json_decode($json, true);
    $chatId = is_array($payload) ? ($payload['session_id'] ?? null) : null;

    return AgentReply::fromContractText($json, is_string($chatId) && $chatId !== '' ? $chatId : null);
}
```

Replace every `AgentReply::fromStream(` in `tests/Unit/WorkflowTest.php` with `factoryReply(`. Replace the stream test in `tests/Unit/AgentReplyTest.php` with the `fromContractText` calls already added in Task 3, and change the remaining `AgentReply::fromStream($json)` calls in that file to `AgentReply::fromContractText($json, null)`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest --compact tests/Unit/CursorDriverTest.php tests/Unit/AgentProcessTest.php tests/Unit/AgentReplyTest.php tests/Unit/WorkflowTest.php tests/Unit/ContractTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AgentDriver.php src/AgentProcess.php src/CursorDriver.php src/AgentReply.php src/Contract.php bin/factory tests/Unit/CursorDriverTest.php tests/Unit/AgentProcessTest.php tests/Unit/AgentReplyTest.php tests/Unit/WorkflowTest.php
git add -u src/CursorAgent.php
git commit -m "$(cat <<'EOF'
feat: parse cursor streams in the cursor driver

EOF
)"
```

---

### Task 5: Claude driver

**Files:**
- Create: `src/ClaudeDriver.php`
- Create: `tests/Unit/ClaudeDriverTest.php`

**Interfaces:**
- Consumes: `AgentDriver`, `AgentProcess::run`, `SandboxPaths::forRole`, `AgentReply::fromContractText`, `FactoryStop`
- Produces: `ClaudeDriver` with `name()` `claude` and `sandboxPath()` `.claude/settings.json`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ClaudeDriverTest.php`:

```php
<?php

declare(strict_types=1);

use Access\Factory\ClaudeDriver;
use Access\Factory\FactoryStop;

function claudeDriver(): ClaudeDriver
{
    return new ClaudeDriver(30);
}

it('builds the claude command and resumes only with a session id', function () {
    $driver = claudeDriver();
    $base = [
        'claude',
        '-p',
        '--output-format',
        'stream-json',
        '--model',
        'sonnet',
        '--permission-mode',
        'bypassPermissions',
        '--permission-prompts',
        'none',
        'do the thing',
    ];

    expect($driver->command('/work', 'sonnet', 'do the thing', null))->toBe($base)
        ->and($driver->command('/work', 'sonnet', 'do the thing', ''))->toBe($base)
        ->and($driver->command('/work', 'sonnet', 'do the thing', 'chat-9'))->toBe([
            'claude',
            '-p',
            '--output-format',
            'stream-json',
            '--model',
            'sonnet',
            '--permission-mode',
            'bypassPermissions',
            '--permission-prompts',
            'none',
            '--resume',
            'chat-9',
            'do the thing',
        ]);
});

it('renders claude edit rules and denies its own settings tree', function () {
    $driver = claudeDriver();
    $author = $driver->sandboxConfig('spec_author');
    $implementer = $driver->sandboxConfig('implementer');

    expect($driver->name())->toBe('claude')
        ->and($driver->sandboxPath())->toBe('.claude/settings.json')
        ->and($author['permissions']['allow'])->toBe([])
        ->and($author['permissions']['deny'])->toContain('Edit(.specify/**)')
        ->and($author['permissions']['deny'])->toContain('Edit(.claude/**)')
        ->and($author['permissions']['deny'])->not->toContain('Edit(specs/**/spec.md)')
        ->and($implementer['permissions']['deny'])->toContain('Edit(specs/**/spec.md)');
});

it('parses a claude stream and ignores tool use blocks', function () {
    $reply = claudeDriver()->parse(<<<'JSON'
    {"type":"system","subtype":"init","session_id":"chat-1"}
    {"type":"assistant","message":{"content":[{"type":"text","text":"working"},{"type":"tool_use","name":"Edit"}]},"session_id":"chat-1"}
    {"type":"result","subtype":"success","is_error":false,"result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}
    JSON);

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1')
        ->and($reply->text)->not->toContain('tool_use');
});

it('uses assistant text when the claude result has no result string', function () {
    $reply = claudeDriver()->parse(<<<'JSON'
    {"type":"assistant","message":{"content":[{"type":"text","text":"{\"verdict\":\"approve\",\"issues\":[],\"assumptions\":[]}"}]},"session_id":"chat-2"}
    {"type":"result","subtype":"success","is_error":false,"session_id":"chat-2"}
    JSON);

    expect($reply->status)->toBe('approve')
        ->and($reply->chatId)->toBe('chat-2');
});

it('stops on a claude error result with no factory object', function () {
    expect(fn () => claudeDriver()->parse('{"type":"result","subtype":"error","is_error":true,"result":"API error","session_id":"chat-1"}'))
        ->toThrow(FactoryStop::class, 'API error');
});

it('keeps a factory object from a claude error result', function () {
    $reply = claudeDriver()->parse('{"type":"result","is_error":true,"result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}');

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1');
});

it('applies the claude exit policy', function () {
    $driver = claudeDriver();

    expect(fn () => $driver->outcome(1, '', 'claude broke'))
        ->toThrow(FactoryStop::class, 'claude broke');
    expect(fn () => $driver->outcome(1, 'still working', ''))
        ->toThrow(FactoryStop::class, 'still working');
    expect($driver->outcome(0, 'still working', '')->status)->toBe('unknown');
    expect($driver->outcome(1, '{"status":"done","summary":"ok","assumptions":[]}', 'noise')->status)->toBe('done');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/ClaudeDriverTest.php`

Expected: FAIL with `ClaudeDriver` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `src/ClaudeDriver.php`:

```php
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
            '--permission-prompts',
            'none',
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

        $reply = AgentReply::fromContractText($resultText ?? $assistant, $chatId);
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Unit/ClaudeDriverTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/ClaudeDriver.php tests/Unit/ClaudeDriverTest.php
git commit -m "$(cat <<'EOF'
feat: add the claude code driver

EOF
)"
```

---

### Task 6: Driver on factory state

**Files:**
- Modify: `src/FactoryState.php`
- Create: `tests/Unit/FactoryStateTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `FactoryState` public property `string $driver`, serialized as `driver`. Missing input loads as `cursor`. Any other stored value throws `RuntimeException` `Factory state is unreadable.`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/FactoryStateTest.php`:

```php
<?php

declare(strict_types=1);

use Access\Factory\FactoryState;

function statePayload(array $extra = []): array
{
    return [
        'feature_dir' => 'specs/001-demo',
        'branch' => '001-demo',
        'description' => 'Record a gate pass',
        'next' => 'specify',
        'stop' => null,
        'lock' => null,
        'converge_rounds' => 0,
        'tasks' => [],
        ...$extra,
    ];
}

it('round-trips the driver', function () {
    $state = FactoryState::fromArray(statePayload(['driver' => 'claude']));

    expect($state->driver)->toBe('claude')
        ->and($state->toArray()['driver'])->toBe('claude');
});

it('defaults a state file with no driver to cursor', function () {
    expect(FactoryState::fromArray(statePayload())->driver)->toBe('cursor');
});

it('rejects a stored driver it does not know', function () {
    expect(fn () => FactoryState::fromArray(statePayload(['driver' => 'codex'])))
        ->toThrow(RuntimeException::class, 'Factory state is unreadable.');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/FactoryStateTest.php`

Expected: FAIL because `driver` is missing from `toArray()`.

- [ ] **Step 3: Write the minimal implementation**

Add `public string $driver = 'cursor'` as the last constructor argument of `FactoryState`. In `fromArray()`, read `$data['driver'] ?? 'cursor'`. When the value is a string `cursor` or `claude`, pass it through. When the key is missing, pass `cursor`. Any other value throws `new RuntimeException('Factory state is unreadable.')`. Add `'driver' => $this->driver` to `toArray()`.

Existing positional `new FactoryState(...)` calls keep working because the new argument is last and defaults to `cursor`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Unit/FactoryStateTest.php tests/Unit/WorkflowTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/FactoryState.php tests/Unit/FactoryStateTest.php
git commit -m "$(cat <<'EOF'
feat: store the agent driver on factory state

EOF
)"
```

---

### Task 7: Workflow uses the driver sandbox

**Files:**
- Modify: `src/Workflow.php` (constructor type, `vet`, `prepareWorktree`, `sealWorktree`, `stepPrompt`)
- Modify: `src/Contract.php` (delete `cliConfig()`)
- Modify: `src/DiffGuard.php`
- Modify: `tests/Unit/DiffGuardTest.php`
- Modify: `tests/Unit/WorkflowTest.php`

**Interfaces:**
- Consumes: `AgentDriver` methods `name()`, `sandboxPath()`, `sandboxConfig(string $role): array`. `Contract::cliConfig()` is deleted in this task.
- Produces: `Workflow` constructed with `AgentDriver`. `vet()` reports `$driver->sandboxPath().' needs permissions.allow.'` and no other sandbox path. Worktree prepare writes `sandboxConfig()` to that relative path. Seal restores that same path.

- [ ] **Step 1: Write the failing tests**

Append one dataset row to the `with([...])` list in `tests/Unit/DiffGuardTest.php`:

```php
['spec_author', '.claude/settings.json', false],
```

Add `use Access\Factory\ClaudeDriver;` and `use Access\Factory\CursorDriver;` to `tests/Unit/WorkflowTest.php`. Change `factoryWorkflow()`'s `$agent` parameter type from `AgentClient` to `AgentDriver`. Change `factoryAgent()`'s return type to `AgentDriver`.

Add this stub class at the bottom of `tests/Unit/WorkflowTest.php`, and change `factoryAgent()` plus the two anonymous classes that say `implements AgentClient` so they extend it. Those two classes are the author-chat agent near the `resumes the author chat` test and the feature-test agent near `runs feature tests and coding on different models`. Each keeps its own `run()` method. `factoryAgent()`'s `run()` returns `factoryReply(($this->reply)($cwd, $prompt))`.

```php
class FactoryAgentStub implements AgentDriver
{
    public function __construct(public string $driverName = 'cursor') {}

    public function name(): string
    {
        return $this->driverName;
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
                'deny' => [],
            ],
        ];
    }

    public function command(string $cwd, string $model, string $prompt, ?string $chatId): array
    {
        return [];
    }

    public function parse(string $stream): AgentReply
    {
        return factoryReply($stream);
    }

    public function outcome(int $exitCode, string $stdout, string $stderr): AgentReply
    {
        return $this->parse($stdout);
    }

    public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
    {
        return factoryReply('{"status":"done","summary":"ok","assumptions":[]}');
    }
}
```

`factoryAgent()` becomes:

```php
function factoryAgent(Closure $reply, string $driverName = 'cursor'): AgentDriver
{
    return new class($reply, $driverName) extends FactoryAgentStub
    {
        public function __construct(private Closure $reply, string $driverName = 'cursor')
        {
            parent::__construct($driverName);
        }

        public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
        {
            return factoryReply(($this->reply)($cwd, $prompt));
        }
    };
}
```

Append these tests to `tests/Unit/WorkflowTest.php`:

```php
it('vets only the live driver sandbox file', function () {
    $root = factoryRoot();

    try {
        $cursor = factoryWorkflow($root, new CursorDriver(30))->vet();
        $claude = factoryWorkflow($root, new ClaudeDriver(30))->vet();

        expect($cursor)->not->toContain('.cursor/cli.json needs permissions.allow.')
            ->and($cursor)->not->toContain('.claude/settings.json needs permissions.allow.')
            ->and($claude)->toContain('.claude/settings.json needs permissions.allow.')
            ->and($claude)->not->toContain('.cursor/cli.json needs permissions.allow.');

        file_put_contents($root.'/.cursor/cli.json', '{');

        expect(factoryWorkflow($root, new CursorDriver(30))->vet())
            ->toContain('.cursor/cli.json needs permissions.allow.');
    } finally {
        factoryRemove($root);
    }
});

it('writes the driver sandbox into the worktree', function () {
    $root = factoryRoot();

    try {
        $agent = new class extends FactoryAgentStub
        {
            public ?array $written = null;

            public function sandboxPath(): string
            {
                return '.claude/settings.json';
            }

            public function sandboxConfig(string $role): array
            {
                return [
                    'permissions' => [
                        'allow' => [],
                        'deny' => ['Edit(.claude/**)'],
                    ],
                ];
            }

            public function run(string $cwd, string $model, string $prompt, ?string $chatId): AgentReply
            {
                $decoded = json_decode((string) file_get_contents($cwd.'/.claude/settings.json'), true);
                $this->written = is_array($decoded) ? $decoded : [];

                return factoryReply('{"status":"done","summary":"ok","assumptions":[]}');
            }
        };
        factoryWorkflow($root, $agent)->start('Record a gate pass');

        expect($agent->written['permissions']['deny'] ?? null)->toBe(['Edit(.claude/**)']);
    } finally {
        factoryRemove($root);
    }
});

it('points the specify prompt at cursor skills and a protected claude tree', function () {
    $root = factoryRoot();

    try {
        $prompts = [];
        $agent = factoryAgent(function (string $cwd, string $prompt) use (&$prompts): string {
            $prompts[] = $prompt;

            return '{"status":"done","summary":"ok","assumptions":[]}';
        });
        factoryWorkflow($root, $agent)->start('Record a gate pass');

        expect($prompts[0])->toContain('.cursor/skills/speckit-specify/SKILL.md')
            ->and($prompts[0])->toContain('.claude/');
    } finally {
        factoryRemove($root);
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --compact tests/Unit/DiffGuardTest.php tests/Unit/WorkflowTest.php --filter="vets only|writes the driver|specify prompt|keeps each role"`

Expected: FAIL because `.claude/settings.json` is still allowed, and `vet()` still reports `.cursor/cli.json` for the Claude driver.

- [ ] **Step 3: Write the minimal implementation**

In `src/DiffGuard.php`, add `'.claude'` to the `$trees` array in `isAlwaysProtected()`.

Change `Workflow`'s constructor parameter type from `AgentClient` to `AgentDriver`.

Replace the `.cursor/cli.json` read in `vet()` with:

```php
$sandbox = $this->agent->sandboxPath();
$cli = json_decode((string) file_get_contents($this->root.'/'.$sandbox), true);
if (! is_array($cli) || ! is_array($cli['permissions']['allow'] ?? null)) {
    $problems[] = $sandbox.' needs permissions.allow.';
}
```

In `prepareWorktree()`, replace the `cliConfig` write with:

```php
$this->git->writeJson($path.'/'.$this->agent->sandboxPath(), $this->agent->sandboxConfig($roleName));
```

In `sealWorktree()`, restore `$this->agent->sandboxPath()` instead of `.cursor/cli.json`.

In `stepPrompt()`, add `.claude/` to the protected-path sentence:

```text
Do not create, edit, delete, or rename files under factory/, .cursor/, .claude/, .specify/, .github/, Makefile, phpstan.neon, deptrac.php, or phpunit.xml.
```

Leave `Follow .cursor/skills/{$skill}/SKILL.md` as it is. Delete `Contract::cliConfig()`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest --compact`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Workflow.php src/Contract.php src/DiffGuard.php tests/Unit/DiffGuardTest.php tests/Unit/WorkflowTest.php
git commit -m "$(cat <<'EOF'
feat: let the agent driver own the worktree sandbox

EOF
)"
```

---

### Task 8: Keep a run on the driver it started with

**Files:**
- Modify: `src/Workflow.php` (`start`, `continueRun`)
- Modify: `tests/Unit/WorkflowTest.php`

**Interfaces:**
- Consumes: `FactoryState::$driver`, `AgentDriver::name(): string`
- Produces: `start` persists `$this->agent->name()` on state. `continueRun` throws `FactoryStop` with reason `driver_mismatch` and message beginning `driver_mismatch:` before it acquires the lock or calls the agent.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/WorkflowTest.php`:

```php
it('stores the live driver when a run starts', function () {
    $root = factoryRoot();

    try {
        factoryWorkflow($root, factoryAgent(
            fn (): string => '{"status":"done","summary":"ok","assumptions":[]}',
            'claude',
        ))->start('Record a gate pass');

        $decoded = json_decode((string) file_get_contents($root.'/specs/001-demo/.factory/state.json'), true);

        expect(is_array($decoded) ? ($decoded['driver'] ?? null) : null)->toBe('claude');
    } finally {
        factoryRemove($root);
    }
});

it('stops resume when the stored driver differs and still reports status', function () {
    $root = factoryRoot();

    try {
        $dir = 'specs/001-demo';
        mkdir($root.'/'.$dir.'/.factory', 0777, true);
        file_put_contents($root.'/.specify/feature.json', json_encode(['feature_directory' => $dir]));
        $state = FactoryState::fromArray([
            'feature_dir' => $dir,
            'branch' => '001-demo',
            'description' => 'Record a gate pass',
            'next' => 'specify',
            'driver' => 'cursor',
        ]);
        file_put_contents($root.'/'.$dir.'/.factory/state.json', json_encode($state->toArray())."\n");
        $calls = 0;
        $workflow = factoryWorkflow($root, factoryAgent(function () use (&$calls): string {
            $calls++;

            return '{"status":"done","summary":"ok","assumptions":[]}';
        }, 'claude'));

        expect($workflow->statusText())->toBe('specs/001-demo specify');
        expect($workflow->staleIds())->toBe([]);
        $workflow->unlock();

        expect(fn () => $workflow->resume())->toThrow(FactoryStop::class, 'driver_mismatch');
        expect($calls)->toBe(0);
    } finally {
        factoryRemove($root);
    }
});
```

Add `use Access\Factory\FactoryState;` if it is not already imported.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/WorkflowTest.php --filter="stores the live driver|stored driver differs"`

Expected: FAIL because the saved driver is still `cursor` for a claude stub, and resume does not throw `driver_mismatch`.

- [ ] **Step 3: Write the minimal implementation**

In `start()`, pass `$this->agent->name()` as the `driver` argument of `new FactoryState(...)`.

At the top of `continueRun()`, before `acquireLock()`:

```php
$state = $this->load();
if ($state->driver !== $this->agent->name()) {
    throw new FactoryStop(
        'driver_mismatch',
        'driver_mismatch: this run uses '.$state->driver.' and the current driver is '.$this->agent->name().'.',
    );
}
```

Do not add this check to `statusText()`, `staleIds()`, or `unlock()`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest --compact`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Workflow.php tests/Unit/WorkflowTest.php
git commit -m "$(cat <<'EOF'
feat: keep a run on the driver it started with

EOF
)"
```

---

### Task 9: Select the driver from factory.yaml

**Files:**
- Modify: `bin/factory`
- Modify: `README.md`

**Interfaces:**
- Consumes: `Contract::driver(): string`, `CursorDriver`, `ClaudeDriver`
- Produces: the `factory` binary constructs `CursorDriver` when the contract driver is `cursor` and `ClaudeDriver` when it is `claude`. An illegal value still throws from `Contract::driver()` before the command runs.

- [ ] **Step 1: Write the failing test**

`bin/factory` is a script, so pin the selection with a focused source test that the binary contains the match. Append to `tests/Unit/ContractTest.php` the behavior already covered by `driver()`, and add `tests/Unit/FactoryBinaryTest.php`:

```php
<?php

declare(strict_types=1);

it('constructs the driver named by the contract', function () {
    $binary = (string) file_get_contents(dirname(__DIR__, 2).'/bin/factory');

    expect($binary)->toContain("match (\$contract->driver())")
        ->and($binary)->toContain("'cursor' => new CursorDriver")
        ->and($binary)->toContain("'claude' => new ClaudeDriver");
});
```

This test fails while `bin/factory` still constructs `CursorDriver` directly.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --compact tests/Unit/FactoryBinaryTest.php`

Expected: FAIL because the match is absent.

- [ ] **Step 3: Write the minimal implementation**

In `bin/factory`, import `ClaudeDriver` and `CursorDriver`. Replace the direct construction with:

```php
$driver = match ($contract->driver()) {
    'cursor' => new CursorDriver($contract->agentTimeout(), $log),
    'claude' => new ClaudeDriver($contract->agentTimeout(), $log),
};

$workflow = new Workflow(
    $root,
    $contract,
    new GitRepo($root),
    $driver,
    new ScriptFeatureScaffolder,
    $log,
);
```

Add this section to `README.md` after the opening paragraph:

```markdown
`factory/factory.yaml` selects the agent with `driver`. `cursor` runs `cursor-agent`. `claude` runs Claude Code. A missing key means `cursor`. Model ids stay in `models` and must be ids the selected product accepts.

```yaml
driver: cursor
```
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest --compact`

Expected: PASS

Run: `vendor/bin/phpstan analyse --memory-limit=1G`

Expected: no errors

- [ ] **Step 5: Commit**

```bash
git add bin/factory README.md tests/Unit/FactoryBinaryTest.php
git commit -m "$(cat <<'EOF'
feat: select the agent driver from factory.yaml

EOF
)"
```
