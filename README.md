# wp-boost

Install the official **WordPress agent skills** into your project for Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf, Zed, Gemini CLI, Junie, and OpenCode — the same idea as `laravel/boost`, but for WordPress plugins, themes, and Bedrock stacks.

Skills are sourced from [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills). wp-boost bundles a pinned snapshot and can pull the latest on demand.

---

## Requirements

- **PHP 8.1+**
- **[Composer](https://getcomposer.org) 2.x**

### Don't have Composer yet?

Check first:

```bash
composer --version
```

If you see a version like `Composer version 2.x`, you're ready — skip to [Install](#install).

If the command isn't found, install it:

**macOS / Linux:**
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**Windows:** download and run the installer from [getcomposer.org/download](https://getcomposer.org/download).

Then make sure Composer's global bin directory is on your `PATH` (you'll install wp-boost there):

```bash
# find the path
composer global config bin-dir --absolute
# e.g. /Users/you/.composer/vendor/bin

# add to your shell profile (~/.zshrc, ~/.bashrc)
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

Reload your shell (`source ~/.zshrc`) and verify:

```bash
composer --version
```

---

## Install

```bash
composer global require huzaifaalmesbah/wp-boost
```

Verify:

```bash
wp-boost --version
```

---

## Use

Inside any WordPress plugin, theme, or Bedrock project:

```bash
cd /path/to/your-project
wp-boost install
```

wp-boost will:

1. **Detect your project type** (plugin, classic theme, block theme, Bedrock, core) and pre-select recommended skills.
2. **Detect which AI agents you already use** (by looking for `.claude/`, `.cursor/`, `.github/`, etc.) and pre-select them.
3. Let you confirm or change the selection.
4. Copy the right files into each agent's skills directory and write `wp-boost.lock.json`.

### Non-interactive / CI

`--yes` accepts detected defaults. Override with flags as needed:

```bash
wp-boost install --yes                                      # use detected defaults
wp-boost install --preset=plugin --yes                      # force project type
wp-boost install --agents=claude_code,cursor --yes          # pick agents explicitly
wp-boost install --skills=wp-plugin-development --yes       # pick skills explicitly
wp-boost install --path=/path/to/project --yes              # different project path
```

### Inspect

```bash
wp-boost doctor            # detected project type, detected agents, available skills
```

### Get help

Every command has built-in help (provided by Symfony Console):

```bash
wp-boost                      # show all commands (same as: wp-boost list)
wp-boost --help               # top-level help
wp-boost --version            # print version

wp-boost help install         # help for a specific command
wp-boost help update
wp-boost help doctor

wp-boost install --help       # same thing, -h also works
```

---

## Updating

There are **two kinds of "update"** and it's important to understand the difference.

### 1. Update wp-boost itself (the CLI tool)

Ships new features, bug fixes, and any tweaks to agent definitions (`agents.json`).

```bash
composer global update huzaifaalmesbah/wp-boost
```

(If you installed per-project instead: `composer update huzaifaalmesbah/wp-boost --dev` inside that project.)

### 2. Update the skills content in your project

Two sub-cases:

**A. Re-sync from the bundled snapshot** — useful after you `composer global update` (the new release may include newer bundled skills), or if you manually edited files and want them reset.

```bash
cd /path/to/your-project
wp-boost update
```

**B. Refresh the global bundle, then apply to the current project**

```bash
cd /path/to/your-project
wp-boost update --remote        # shortcut for: wp-boost sync  +  wp-boost update
wp-boost update --remote --upstream   # same, but pulls from WordPress/agent-skills@trunk
```

**C. Refresh the global bundle only — no project needed**

```bash
# works from anywhere, doesn't touch any project
wp-boost sync                   # default: pull from huzaifaalmesbah/wp-boost@main (vetted)
wp-boost sync --upstream        # opt-in: pull from WordPress/agent-skills@trunk (bleeding edge)
```

Afterwards, run `wp-boost update` inside each project you want to apply the new skills to. This is the fastest way to update many projects: refresh the bundle once, then cheap local syncs everywhere else.

### Two sources to sync from

| Source | When to use | Trade-off |
|---|---|---|
| **`huzaifaalmesbah/wp-boost@main`** (default) | Normal use | Vetted through PR review, ≤7 days behind upstream |
| **`WordPress/agent-skills@trunk`** (`--upstream`) | You want the absolute latest or are debugging upstream changes | Unvetted — may contain broken/in-progress content |

### What exactly does `sync` / `--remote` change?

Both refresh **wp-boost's own bundled `skills/` directory** (a copy that lives inside the installed wp-boost package itself, not inside any project).

| How wp-boost is installed | What gets modified | Affects other projects? |
|---|---|---|
| **Global** (`composer global require`) | `~/.composer/vendor/huzaifaalmesbah/wp-boost/skills/` | ✅ Yes — every project will see the new skills next time you run `wp-boost install` or `wp-boost update` in it |
| **Per-project** (`composer require --dev`) | `that-project/vendor/huzaifaalmesbah/wp-boost/skills/` | ❌ No — only that project's vendored copy |

`wp-boost update --remote` does the sync **and** re-syncs the current project in one step. Plain `wp-boost sync` just refreshes the bundle.

### "How do I know a project needs `wp-boost update`?"

wp-boost stamps the bundle's commit SHA into every `wp-boost.lock.json` on install/update. Whenever you run `wp-boost doctor`, it compares the project's stamped SHA against the current bundle's SHA and prints a warning if they differ:

```
Bundle:   def5678 from huzaifaalmesbah/wp-boost@main (2026-04-14T08:30:00+00:00)
Project:  abc1234 (installed 2026-04-10T14:02:00+00:00)

⚠  Project is using skills from bundle abc1234; current bundle is def5678.
   Run `wp-boost update` to apply the new skills.
```

After `wp-boost update`, the SHAs match and the warning goes away.

### Typical workflows

**Global-install users (most common):**

```bash
# refresh the global bundle once, from anywhere
wp-boost sync

# then apply to each project you care about
cd ~/project-a && wp-boost update      # fast local sync, no network
cd ~/project-b && wp-boost update
```

**Per-project install:** run `wp-boost update --remote` inside each project you want to refresh.

---

## Commands

| Command | Purpose |
|---|---|
| `wp-boost install` | Interactive install with auto-detection. Flags: `--agents=`, `--skills=`, `--preset=`, `--yes`, `--path=` |
| `wp-boost update` | Re-sync installed skills from the bundled snapshot. |
| `wp-boost update --remote` | Refresh the bundle (see `sync` below), then re-sync the current project. |
| `wp-boost sync` | Refresh the global bundle from `huzaifaalmesbah/wp-boost@main`. No project needed — run from anywhere. |
| `wp-boost sync --upstream` | Refresh the global bundle from `WordPress/agent-skills@trunk` (bleeding edge, bypasses vetted channel). |
| `wp-boost doctor` | Print detected project type, detected agents, and available skills. |

---

## Supported agents

Driven by [`agents.json`](./agents.json):

| Agent | Skills directory |
|---|---|
| Claude Code | `.claude/skills/` |
| Cursor | `.cursor/rules/` (`.mdc` format) |
| GitHub Copilot | `.github/instructions/` |
| OpenAI Codex CLI | `.codex/skills/` |
| Windsurf | `.windsurf/rules/` |
| Zed | `.zed/skills/` |
| Gemini CLI | `.gemini/skills/` |
| JetBrains Junie | `.junie/skills/` |
| OpenCode | `.opencode/skills/` |

---

## What gets written to your project

After `wp-boost install` (depending on agents selected):

```
your-project/
├── .claude/skills/<skill-name>/SKILL.md
├── .cursor/rules/<skill-name>.mdc
├── .github/instructions/<skill-name>.md
├── ...
└── wp-boost.lock.json         # records installed agents + skills
```

Re-running `wp-boost install` or `wp-boost update` overwrites existing skill files with the latest content — safe to do anytime.

---

## Uninstall

```bash
composer global remove huzaifaalmesbah/wp-boost
```

Installed skill files stay in your project. Delete `.claude/skills/`, `.cursor/rules/`, etc. manually and remove `wp-boost.lock.json` if you no longer want them.

---

## Contributing & reporting issues

Where you file things depends on what you're reporting — the CLI tool lives here, the skill content lives upstream at [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills).

| If you want to… | File it at |
|---|---|
| Report a skill-content bug (wrong info inside a `SKILL.md`) | [WordPress/agent-skills · issues](https://github.com/WordPress/agent-skills/issues) · [PRs](https://github.com/WordPress/agent-skills/pulls) |
| Propose a new skill | [WordPress/agent-skills · issues](https://github.com/WordPress/agent-skills/issues) · [PRs](https://github.com/WordPress/agent-skills/pulls) |
| Report a wp-boost CLI bug (install/update/sync/doctor) | [huzaifaalmesbah/wp-boost · issues](https://github.com/huzaifaalmesbah/wp-boost/issues) · [PRs](https://github.com/huzaifaalmesbah/wp-boost/pulls) |
| Add support for a new AI agent | [huzaifaalmesbah/wp-boost · issues](https://github.com/huzaifaalmesbah/wp-boost/issues) · [PRs](https://github.com/huzaifaalmesbah/wp-boost/pulls) |
| Improve detection, prompts, docs, CI | [huzaifaalmesbah/wp-boost · issues](https://github.com/huzaifaalmesbah/wp-boost/issues) · [PRs](https://github.com/huzaifaalmesbah/wp-boost/pulls) |

See [CONTRIBUTING.md](./CONTRIBUTING.md) for the full dev guide: local setup, project structure, adding a new AI agent, testing, and release process.

---

## Credits

- **[WordPress/agent-skills](https://github.com/WordPress/agent-skills)** — the WordPress contributors who author and maintain the skills this tool installs. wp-boost is just a distributor; all skill content belongs to them.
- **The WordPress community** — for building and maintaining the ecosystem this tool serves.
- Inspired by [`laravel/boost`](https://github.com/laravel/boost), which does the same job for the Laravel ecosystem.

---

## License

wp-boost is licensed under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later), matching [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills) and the wider WordPress ecosystem. See [LICENSE](./LICENSE) for the full text.

The bundled content in `skills/` is an unmodified redistribution from `WordPress/agent-skills` and remains under its original GPL-2.0-or-later license, copyright the WordPress contributors.
