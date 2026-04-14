# Contributing to wp-boost

Thanks for your interest in improving wp-boost! This guide covers everything you need to run the tool from source, make changes, and submit them.

## Where to file things

wp-boost is the **installer/distributor**. The actual agent skill content lives upstream at [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills). File issues in the right repo so the right people see them.

| If you want to… | File it at |
|---|---|
| Report a skill-content bug (wrong info inside a `SKILL.md`) | [WordPress/agent-skills · issues](https://github.com/WordPress/agent-skills/issues) · [PRs](https://github.com/WordPress/agent-skills/pulls) |
| Propose a new skill | [WordPress/agent-skills · issues](https://github.com/WordPress/agent-skills/issues) · [PRs](https://github.com/WordPress/agent-skills/pulls) |
| Report a wp-boost CLI bug (install/update/sync/doctor) | [huzaifaalmesbah/wp-boost · issues](https://github.com/huzaifaalmesbah/wp-boost/issues) · [PRs](https://github.com/huzaifaalmesbah/wp-boost/pulls) |
| Add support for a new AI agent | [huzaifaalmesbah/wp-boost · issues](https://github.com/huzaifaalmesbah/wp-boost/issues) · [PRs](https://github.com/huzaifaalmesbah/wp-boost/pulls) |
| Improve detection, prompts, docs, CI | [huzaifaalmesbah/wp-boost · issues](https://github.com/huzaifaalmesbah/wp-boost/issues) · [PRs](https://github.com/huzaifaalmesbah/wp-boost/pulls) |

Once a change is merged upstream at `WordPress/agent-skills`, the scheduled [`sync-skills` workflow](./.github/workflows/sync-skills.yml) opens a PR in this repo to refresh our bundled snapshot — usually within 7 days.

## Prerequisites

- **PHP 8.1+** (CI runs against 8.1 → 8.5)
- **Composer 2.x**
- **Git**

```bash
php --version
composer --version
```

## Get the source

```bash
git clone https://github.com/huzaifaalmesbah/wp-boost.git
cd wp-boost
composer install
```

Verify it runs:

```bash
php bin/wp-boost --version
php bin/wp-boost doctor
php bin/wp-boost list
```

## Running your local copy

Three options, from simplest to most "installed-like":

### Option 1 — direct invocation (fastest)

```bash
php /path/to/wp-boost/bin/wp-boost install --path=/path/to/test-project
```

### Option 2 — Composer path repository (recommended for iterating)

```bash
cd /path/to/test-project
composer config repositories.wp-boost path /absolute/path/to/wp-boost
composer require huzaifaalmesbah/wp-boost:@dev --dev
./vendor/bin/wp-boost install
```

Composer **symlinks** the package, so any code change in your checkout is picked up immediately — no re-install.

### Option 3 — global install from your checkout

```bash
composer global config repositories.wp-boost path /absolute/path/to/wp-boost
composer global require huzaifaalmesbah/wp-boost:@dev
wp-boost --version
```

To revert later:

```bash
composer global remove huzaifaalmesbah/wp-boost
```

## Project structure

```
wp-boost/
├── bin/wp-boost               # CLI entrypoint (PHP)
├── src/
│   ├── Agents/                # agents.json loader + agent detection
│   ├── Commands/              # install, update, sync, doctor
│   ├── Detection/             # project-type detection (plugin/theme/bedrock/…)
│   ├── Skills/                # fetcher, composer, writer, bundle metadata
│   └── Support/               # Paths, Freshness
├── skills/                    # bundled snapshot of WordPress/agent-skills
│   └── .bundle.json           # syncedAt + commit SHA + source (never hand-edit)
├── agents.json                # supported AI agents
├── composer.json
└── .github/workflows/         # CI matrix + weekly upstream sync
```

**Never hand-edit `skills/` or `skills/.bundle.json`.** The weekly sync workflow manages that content.

## Commands (recap for contributors)

| Command | What it does |
|---|---|
| `wp-boost install` | Interactive install into a project; writes `wp-boost.lock.json` |
| `wp-boost update` | Re-sync installed agents from the bundle (no network) |
| `wp-boost update --remote` | Sync bundle from default source, then re-sync the current project |
| `wp-boost update --remote --upstream` | Same, but pull from `WordPress/agent-skills@trunk` |
| `wp-boost sync` | Refresh the bundle from `huzaifaalmesbah/wp-boost@main` (vetted). Run from anywhere. |
| `wp-boost sync --upstream` | Refresh the bundle from `WordPress/agent-skills@trunk` (bleeding edge). |
| `wp-boost doctor` | Show detection results, bundle SHA, project SHA, freshness banner |

## Making changes

1. **Open or comment on an issue first** for non-trivial work so we can align on approach: [huzaifaalmesbah/wp-boost/issues](https://github.com/huzaifaalmesbah/wp-boost/issues).
2. Create a branch: `git checkout -b feat/your-change` (or `fix/…`, `docs/…`, `chore/…`).
3. Keep changes focused — one topic per PR.
4. Follow existing code style: strict types, final classes, Symfony Console conventions.
5. Test locally (see below).
6. Open a PR: [huzaifaalmesbah/wp-boost/pulls](https://github.com/huzaifaalmesbah/wp-boost/pulls). Link any related issue with `Fixes #N`.

## Adding a new AI agent

Edit [`agents.json`](./agents.json) and add an entry:

```json
"your_agent": {
    "displayName": "Your Agent",
    "detect": { "paths": [".youragent"], "files": ["AGENT.md"], "commands": ["youragent"] },
    "skillsPath": ".youragent/skills",
    "guidelinesPath": ".youragent/guidelines.md"
}
```

| Field | Purpose |
|---|---|
| `displayName` | Shown in the interactive picker. |
| `detect` | Auto-selection hints: `paths`, `files`, and/or `commands`. Any match wins. |
| `skillsPath` | Relative target dir inside the user's project. |
| `guidelinesPath` | Optional — where the agent looks for top-level guidelines. |
| `skillFormat` | `md` (default, directory mirror) or `mdc` (single-file Cursor format). |

Test the detection by creating the matching file/folder in a scratch dir, then running `wp-boost doctor`.

Open a PR at [huzaifaalmesbah/wp-boost/pulls](https://github.com/huzaifaalmesbah/wp-boost/pulls).

## Local testing

Reproduce what CI runs:

```bash
composer validate --strict
find src bin -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/wp-boost doctor
```

Full install-into-fixture smoke test:

```bash
# fake plugin project
mkdir -p /tmp/fixture && cd /tmp/fixture
echo '<?php /* Plugin Name: Demo */' > demo.php

# install
php /path/to/wp-boost/bin/wp-boost install \
    --preset=plugin \
    --agents=claude_code,cursor \
    --yes

# assert
test -d .claude/skills/wp-plugin-development && echo ok
test -f .cursor/rules/wp-plugin-development.mdc && echo ok
test -f wp-boost.lock.json && echo ok

# refresh the bundle from upstream
php /path/to/wp-boost/bin/wp-boost sync --upstream

# update project — SHAs should match after, freshness banner should be gone
php /path/to/wp-boost/bin/wp-boost update
php /path/to/wp-boost/bin/wp-boost doctor
```

## CI

Two workflows:

- [`ci.yml`](./.github/workflows/ci.yml) — PHP matrix 8.1 → 8.5: `composer validate`, `php -l`, `doctor`, install fixture, update, `sync --upstream`, bundle-metadata assertion. Runs on every push/PR.
- [`sync-skills.yml`](./.github/workflows/sync-skills.yml) — weekly cron (Mon 06:00 UTC) and `workflow_dispatch`: pulls upstream `trunk`, writes `skills/.bundle.json`, opens a review PR.

## Releasing

(For maintainers.) Tag-driven:

```bash
git tag v0.x.y
git push --tags
```

Packagist picks up the tag automatically via the GitHub webhook. Users catch up with:

```bash
composer global update huzaifaalmesbah/wp-boost
```

## Code of conduct

Be kind, assume good faith, keep discussions on-topic. Since this is a WordPress-ecosystem tool, the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/) applies here too.

## License

By contributing, you agree that your contributions will be licensed under **GPL-2.0-or-later**, the same license as the project. See [LICENSE](./LICENSE).

## Useful links

- wp-boost issues: https://github.com/huzaifaalmesbah/wp-boost/issues
- wp-boost PRs: https://github.com/huzaifaalmesbah/wp-boost/pulls
- wp-boost new issue: https://github.com/huzaifaalmesbah/wp-boost/issues/new
- WordPress/agent-skills issues: https://github.com/WordPress/agent-skills/issues
- WordPress/agent-skills PRs: https://github.com/WordPress/agent-skills/pulls
- WordPress/agent-skills new issue: https://github.com/WordPress/agent-skills/issues/new
