# Laravel Analyzer — Gemini CLI Context

This file is automatically loaded by Gemini CLI when working in this directory.

## Project Summary

`laravel-analyzer` is a static analysis CLI for Laravel projects (pure PHP 8.1+, zero dependencies).

## Running Analysis

```bash
# Console output (human-readable)
php bin/laravel-analyze /path/to/laravel-project

# JSON output (structured, for agent consumption)
php bin/laravel-analyze /path/to/laravel-project --format=json

# Specific modules
php bin/laravel-analyze . --only=security,owasp

# Quality gate
php bin/laravel-analyze . --threshold=75
```

## MCP Server

```bash
php bin/laravel-analyze-mcp /path/to/laravel-project
```

Available MCP tools: `analyze`, `analyze_module`, `get_issues`, `get_recommendations`

## Modules

- `coupling` — God Classes, coupling, SRP violations
- `testing` — Test coverage, clover.xml, assertion density
- `debt` — TODO/FIXME/HACK, anti-patterns, composer issues
- `complexity` — Cyclomatic complexity, nesting, duplication
- `security` — SQL Injection, XSS, Mass Assignment, command injection
- `owasp` — OWASP Top 10 (2021) compliance

## Scores

100 = perfect · 60 = acceptable · below 60 = failing threshold

## Project Conventions

- Namespace: `LaravelAnalyzer\` → `src/`
- No external composer dependencies
- Analyzers return: `{ score, metrics, issues, recommendations }`
- README is in English
