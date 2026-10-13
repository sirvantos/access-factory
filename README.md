# access/factory

Orchestrator for a Speckit repository. This package is the engine. The consumer repository keeps the contract and the prompts:

- `factory/factory.yaml`
- `factory/prompts/`

Run it from the project root, or from any directory under that root. The binary walks upward until it finds `factory/factory.yaml`.

`factory/factory.yaml` selects the agent with `driver`. `cursor` runs `cursor-agent`. `claude` runs Claude Code. A missing key means `cursor`. Model ids stay in `models` and must be ids the selected product accepts.

```yaml
driver: cursor
```

```bash
vendor/bin/factory vet
vendor/bin/factory run -i specs/001.md
vendor/bin/factory run --fast -v -i specs/001.md
vendor/bin/factory resume
```
