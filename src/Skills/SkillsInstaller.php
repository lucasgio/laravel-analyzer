<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Skills;

/**
 * Installs best-practice skill files into a Laravel project.
 *
 * For Claude Code: .claude/commands/*.md  (slash commands)
 * For Cursor     : .cursor/rules/*.mdc    (always-on rules)
 *
 * Usage:
 *   php bin/laravel-analyze install-skills /path/to/laravel-project
 *   php bin/laravel-analyze install-skills /path/to/laravel-project --only=laravel-api,react
 */
class SkillsInstaller
{
    private const SKILLS = [
        'laravel'     => 'Laravel General Best Practices',
        'laravel-api' => 'Laravel API REST Best Practices',
        'react'       => 'React Best Practices',
        'vue-inertia' => 'Vue.js + Inertia.js Best Practices',
    ];

    private string $projectPath;
    private array  $log = [];

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, '/');
    }

    /**
     * Install all or a subset of skills.
     *
     * @param  string[]|null $only  Skill keys to install; null = all
     * @return array{installed: string[], skipped: string[], log: string[]}
     */
    public function install(?array $only = null): array
    {
        $installed = [];
        $skipped   = [];

        foreach (self::SKILLS as $key => $label) {
            if ($only !== null && !in_array($key, $only, true)) {
                $skipped[] = $label;
                continue;
            }

            $content = $this->getSkillContent($key);
            if ($content === null) {
                $skipped[] = $label;
                continue;
            }

            $this->writeClaudeCommand($key, $content['claude']);
            $this->writeCursorRule($key, $content['cursor']);

            $installed[] = $label;
        }

        $this->writeSetupGuide();

        return [
            'installed' => $installed,
            'skipped'   => $skipped,
            'log'       => $this->log,
        ];
    }

    public static function availableSkills(): array
    {
        return self::SKILLS;
    }

    // ------------------------------------------------------------------

    private function writeClaudeCommand(string $key, string $content): void
    {
        $dir  = $this->projectPath . '/.claude/commands';
        $file = $dir . '/' . $key . '.md';
        $this->ensureDir($dir);
        file_put_contents($file, $content);
        $this->log[] = "Installed Claude Code command: .claude/commands/{$key}.md";
    }

    private function writeCursorRule(string $key, string $content): void
    {
        $dir  = $this->projectPath . '/.cursor/rules';
        $file = $dir . '/' . $key . '.mdc';
        $this->ensureDir($dir);
        file_put_contents($file, $content);
        $this->log[] = "Installed Cursor rule: .cursor/rules/{$key}.mdc";
    }

    private function writeSetupGuide(): void
    {
        $guide = $this->buildSetupGuide();
        $file  = $this->projectPath . '/AGENT_SETUP.md';
        file_put_contents($file, $guide);
        $this->log[] = "Created setup guide: AGENT_SETUP.md";
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function getSkillContent(string $key): ?array
    {
        return match ($key) {
            'laravel'     => ['claude' => LaravelSkillContent::claude(),     'cursor' => LaravelSkillContent::cursor()],
            'laravel-api' => ['claude' => LaravelApiSkillContent::claude(),  'cursor' => LaravelApiSkillContent::cursor()],
            'react'       => ['claude' => ReactSkillContent::claude(),       'cursor' => ReactSkillContent::cursor()],
            'vue-inertia' => ['claude' => VueInertiaSkillContent::claude(),  'cursor' => VueInertiaSkillContent::cursor()],
            default       => null,
        };
    }

    private function buildSetupGuide(): string
    {
        return <<<'MD'
# Agent Setup Guide — Laravel Best Practices Skills

This file documents the skills installed by `laravel-analyzer install-skills`.

---

## What was installed

| Skill | Claude Code | Cursor |
|---|---|---|
| Laravel General | `.claude/commands/laravel.md` | `.cursor/rules/laravel.mdc` |
| Laravel API REST | `.claude/commands/laravel-api.md` | `.cursor/rules/laravel-api.mdc` |
| React | `.claude/commands/react.md` | `.cursor/rules/react.mdc` |
| Vue.js + Inertia | `.claude/commands/vue-inertia.md` | `.cursor/rules/vue-inertia.mdc` |

---

## Claude Code — How to use

### Invoke a skill manually

Type the slash command in the chat:

```
/laravel          # Laravel general best practices
/laravel-api      # Laravel API REST best practices
/react            # React best practices
/vue-inertia      # Vue + Inertia best practices
```

### Make an agent always follow best practices

Add a reference in your `CLAUDE.md`:

```markdown
## Development Standards

Before writing any code, consult the relevant best-practice skill:
- Laravel code → run /laravel
- API endpoints → run /laravel-api
- React components → run /react
- Vue/Inertia pages → run /vue-inertia
```

### Auto-apply on every task (hook-based)

In `.claude/settings.json`:

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "echo 'Reminder: follow the installed best-practice skills in .claude/commands/'"
          }
        ]
      }
    ]
  }
}
```

---

## Cursor — How to use

The `.mdc` files in `.cursor/rules/` are loaded **automatically** by Cursor for every file in the project.

### Configure rule scope

Edit each `.mdc` file header to target specific file patterns:

```
---
description: Laravel API best practices
globs: app/Http/Controllers/**/*.php, routes/api.php
alwaysApply: false
---
```

Set `alwaysApply: true` to load the rule for every file in the project.

### Attach a rule to a specific chat

In Cursor chat, type `@Rules` and select the rule you want to apply.

---

## Tips for both tools

1. **Be specific in prompts** — mention the skill: *"Following the Laravel API best practices, create a paginated endpoint for orders"*
2. **Chain skills** — when building a feature: apply `laravel` first, then `laravel-api` for the controller layer
3. **Update skills** — re-run `php bin/laravel-analyze install-skills .` after upgrading `laravel-analyzer` to get updated best practices
MD;
    }
}
