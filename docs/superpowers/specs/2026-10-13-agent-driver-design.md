# Agent driver

The factory launches one inner agent for a run. Today that agent is `cursor-agent`, and the worktree sandbox is `.cursor/cli.json`. `factory/factory.yaml` gains a `driver` key so the same run can use Cursor CLI or Claude Code. Skills stay in `.cursor/skills/` for both.

## Driver boundary

`AgentDriver` extends `AgentClient` and is the only collaborator `Workflow` uses for the process and the sandbox. `bin/factory` builds the implementation named in config and passes it in.

The interface adds three methods to `run()`:

- `name(): string` returns `cursor` or `claude`.
- `sandboxPath(): string` returns the sandbox file relative to a worktree.
- `sandboxConfig(string $role): array` returns the role sandbox document.

`CursorDriver` replaces `CursorAgent`. `ClaudeDriver` is the second implementation. `Contract::cliConfig()` moves off `Contract`. The shared deny paths live in one list, `SandboxPaths::forRole(string $role)`. Each driver renders that list into its own file.

`Workflow`'s constructor takes `AgentDriver`. It stops naming `.cursor/cli.json`. It writes `sandboxConfig()` to `sandboxPath()` when preparing a worktree, and seal restores that same path. `vet` checks the sandbox file of the driver named in yaml.

Both drivers use the contract agent timeout. Both stream process output to the factory log.

`AgentReply` stays the object `Workflow` already uses: status, text, chat id, payload, and the review gates. It stops walking vendor event streams. `fromStream()` is removed. `fromContractText(string $text, ?string $chatId)` finds the factory JSON object (`status` or `verdict`) in text the driver has already extracted. Each driver's `run()` collects process output and parses that product's stream. `parse(string $stream): AgentReply` is public so tests can feed a fixture without spawning a CLI.

A driver extracts a session id and the assistant text from its own events, then calls `fromContractText()`. Review-gate tests build `AgentReply` through `fromContractText()`. Vendor stream fixtures live with the drivers.

`.cursor/skills/` remains the skill path in prompts. `DiffGuard` protects `.cursor/` and `.claude/`. The specify-step prompt lists `.claude/` with the other paths the agent must leave untouched.

## Configuration and run lifecycle

`factory/factory.yaml` accepts:

```yaml
driver: cursor
```

`cursor` and `claude` are the legal values. A missing key means `cursor`. Any other value throws from `Contract::driver()` while the binary resolves the driver, before `vet`, `run`, or `resume`.

`start` stores that value on `state.json` as `driver`, next to `mode`. `FactoryState` round-trips the field. A state file with no `driver` field loads as `cursor`. A stored value other than `cursor` or `claude` makes the state unreadable.

`continueRun` compares the stored driver with `name()` before any agent call. A difference throws `FactoryStop` with reason `driver_mismatch` and does not call the agent. `status`, `stale`, and `unlock` do not perform that check.

`vet` uses the yaml driver. It does not read an in-progress feature's stored driver.

Models stay in the existing `models` map. The selected product receives those ids as-is.

## Shared deny paths

`SandboxPaths::forRole()` returns these paths for every role:

- `.specify/**`
- `.cursor/**`
- `factory/**`
- `.github/**`
- `.gitlab-ci.yml`
- `.gitlab/**`
- `Makefile`
- `phpstan.neon`
- `deptrac.php`
- `phpunit.xml`

Roles `implementer` and `feature_tester` also include:

- `specs/**/spec.md`
- `specs/**/plan.md`
- `specs/**/tasks.md`
- `specs/**/research.md`
- `specs/**/data-model.md`
- `specs/**/quickstart.md`
- `specs/**/constraints.md`
- `specs/**/contracts/**`
- `specs/**/checklists/**`

`converge` uses the base list.

## Cursor

Command, with the worktree as the process directory:

```text
cursor-agent -p --output-format stream-json --model <model> --trust --force --workspace <worktree> [--resume <chat id>] <prompt>
```

`--resume` is present only when the chat id is non-empty.

The sandbox file is `.cursor/cli.json`:

```json
{
  "permissions": {
    "allow": [],
    "deny": ["Write(.specify/**)"]
  }
}
```

Each shared path is rendered as `Write(<path>)`.

`vet` requires `.cursor/cli.json` at the repo root with `permissions.allow` present as an array. A missing file, invalid JSON, or a missing `permissions.allow` array produces `.cursor/cli.json needs permissions.allow.` A Cursor vet does not check `.claude/settings.json`.

The parser accepts this stream. The session id is `chat-1`. The reply status is `done`.

```json
{"type":"system","session_id":"chat-1"}
{"type":"assistant","message":{"content":[{"text":"working"}]}}
{"type":"result","result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}
```

A non-zero exit with empty stdout stops as `agent_failed`, using the process error text. A non-zero exit whose stdout contains a factory JSON object returns that reply. A non-zero exit whose stdout has no factory JSON object stops as `agent_failed`. Any other stream with no factory JSON object returns an `AgentReply` whose status is `unknown`.

## Claude

Command, with the worktree as the process directory:

```text
claude -p --output-format stream-json --model <model> --permission-mode bypassPermissions --permission-prompts none [--resume <session id>] <prompt>
```

`--resume` is present only when the session id is non-empty. Session persistence stays on. `--include-partial-messages` and `--bare` stay off.

The sandbox file is `.claude/settings.json` with the same `permissions.allow` / `permissions.deny` shape. Claude matches file writes with `Edit(path)` rules, and those rules cover Write, so each shared path is rendered as `Edit(<path>)`. The Claude deny list also includes `Edit(.claude/**)`. `bypassPermissions` still honors those deny rules. `--permission-prompts none` denies a leftover prompt so the run does not wait.

`vet` requires `.claude/settings.json` at the repo root with `permissions.allow` present as an array. The message is `.claude/settings.json needs permissions.allow.` A Claude vet does not check `.cursor/cli.json`.

The parser reads Claude Code stream-json. It takes `session_id` from the events. It ignores `tool_use` content blocks. The assistant text is the string `result` on the final `result` event, and when that field is absent it is the concatenated assistant `text` blocks. It then hands that text to `AgentReply`.

This stream yields session id `chat-1` and status `done`:

```json
{"type":"system","subtype":"init","session_id":"chat-1"}
{"type":"assistant","message":{"content":[{"type":"text","text":"working"},{"type":"tool_use","name":"Edit"}]},"session_id":"chat-1"}
{"type":"result","subtype":"success","is_error":false,"result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}
```

A result event with `is_error` true and no factory JSON object stops as `agent_failed`, using the result text. A non-zero exit with empty stdout stops as `agent_failed`, using the process error text. A non-zero exit whose stdout has no factory JSON object stops as `agent_failed`. When a factory JSON object is present, the driver returns that `AgentReply`. A zero exit with no error event and no factory JSON object returns status `unknown`.

## Failure rules

| Situation | Result |
| --- | --- |
| Illegal `driver` value | Throw while resolving the driver. Exit 1. |
| Missing `driver` key | Use `cursor`. |
| Stored driver differs from yaml on `continueRun` | `FactoryStop` reason `driver_mismatch`. Exit 2. No agent call. |
| Non-zero exit, empty stdout | `agent_failed` with the process error text. |
| Cursor non-zero exit, stdout has no factory JSON | `agent_failed`. |
| Claude `is_error` result, no factory JSON | `agent_failed` with the result text. |
| Claude non-zero exit, stdout has no factory JSON | `agent_failed`. |
| Factory JSON present | Return `AgentReply`. |
| Zero exit, no error event, no factory JSON | `AgentReply` status `unknown`. |
| Sandbox file missing or without `permissions.allow` | One `vet` problem for the yaml driver's file. |

## Tests and docs

- `Contract` accepts `cursor` and `claude`, defaults a missing key to `cursor`, and throws on any other value.
- `FactoryState` round-trips `driver`. A file without the field loads as `cursor`. Any other stored value is unreadable.
- `CursorDriver` tests cover the command, the `Write(...)` sandbox document for a base role and for `implementer`, and the success and failure streams above.
- `ClaudeDriver` tests cover the command, the `Edit(...)` sandbox document including `Edit(.claude/**)`, and the success and `is_error` streams above.
- Workflow tests keep a stub driver whose `sandboxPath()` is `.cursor/cli.json`, so current worktree fixtures stay valid. The stub does not spawn a CLI.
- New workflow cases cover the driver saved by `start`, the `driver_mismatch` stop, both `vet` messages, and the sandbox path written into a worktree.
- `DiffGuard` rejects a change under `.claude/`.
- The README lists `driver` and the two values.
