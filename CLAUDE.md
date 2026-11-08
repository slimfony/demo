# Working on slimfony/demo

A showcase service built on [slimfony/core](https://github.com/slimfony/core). The core's `GUIDELINES.md` applies here too, section 6 (Definition of done) included.

- PHP 8.4+, every package at its latest stable release (`composer outdated`).
- Run `composer ci` before every commit and check its **exit code**. Commit small and often on `main`, push, and confirm the GitHub Actions run is green.
- `PLAN.md` is local and gitignored: never commit it.
- The demo is English only: UI, docs, code, commit messages.
- Same code style as the core: final, `@api`/`@internal`, typed constants, `#[\Override]`, no statics, injected instances. `tests/ArchitectureTest.php` enforces most of it.
- Tests are few, behavioural and deterministic. `scripts/e2e.sh` checks the running stack.
- Production settings (Caddy, PHP, containers) are the point of the demo: do not loosen them to make something easier.
