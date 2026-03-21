# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`laravel-analyzer` is a standalone PHP CLI tool that performs static analysis on Laravel applications. It requires **zero external dependencies** — pure PHP 8.1+ only. The tool analyzes code quality, security, and maintainability across 6 independent modules.

## Running the Tool

```bash
# Analyze a Laravel project
php bin/laravel-analyze /path/to/laravel-project

# With specific modules only
php bin/laravel-analyze . --only=security,owasp

# JSON output for CI/CD
php bin/laravel-analyze . --format=json --output=report.json --no-color

# Set minimum quality threshold (exits with code 1 if score < threshold)
php bin/laravel-analyze . --threshold=75
```

Available `--format` values: `console` (default), `json`, `html`, `markdown`
Available `--only` modules: `coupling`, `testing`, `debt`, `complexity`, `security`, `owasp`

There are no build, compile, or test steps — this is a distribution-ready tool.

## Architecture

**Entry point**: `bin/laravel-analyze` bootstraps PSR-4 autoloading (with manual fallback) and runs `LaravelAnalyzer\Console\Application`.

**Execution flow**:
1. `Application.php` parses CLI args and validates the target is a Laravel project (checks for `artisan`, `app/Http/Kernel.php`, `composer.json`)
2. Each analyzer runs sequentially, returning a `['score', 'metrics', 'issues', 'recommendations']` array
3. `ReportGenerator` aggregates results into the requested output format
4. Global score = average of all 6 module scores → grade (A+ through F) → threshold check → exit code

**Analyzers** (`src/Analyzers/`): All extend `BaseAnalyzer`, which provides `getPhpFiles()`, `readFile()`, `extractClassName()`, etc.

| Analyzer | What it detects |
|---|---|
| `CouplingCohesionAnalyzer` | God Classes, high coupling, long methods, SRP violations |
| `TestCoverageAnalyzer` | Test count, clover.xml coverage parsing, test quality |
| `TechnicalDebtAnalyzer` | FIXME/TODO/HACK, `$guarded = []`, config anti-patterns |
| `ComplexityAnalyzer` | Cyclomatic complexity per method, deep nesting, duplication |
| `SecurityAnalyzer` | SQL injection, mass assignment, XSS, command injection, weak hashing |
| `OwaspAnalyzer` | Maps vulnerabilities to OWASP Top 10 (2021) categories |

**Reports** (`src/Reports/ReportGenerator.php`): Generates console (ANSI), JSON, HTML, and Markdown from the same result set.

## Key Conventions

- **Namespace**: `LaravelAnalyzer\` maps to `src/` via PSR-4
- **No external packages**: Do not add composer dependencies. Any utility needed must be implemented in pure PHP.
- **Analyzer contract**: Each analyzer's `analyze(string $projectPath): array` must return keys `score` (0–100 int), `metrics` (assoc array), `issues` (array of strings), `recommendations` (array of strings).
- **README is in Spanish** — keep it that way when editing documentation.
