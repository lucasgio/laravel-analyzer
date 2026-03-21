# Laravel Analyzer — GitHub Copilot Instructions

## What this project is

`laravel-analyzer` is a standalone PHP CLI + MCP server for static analysis of Laravel projects.
Zero external dependencies — pure PHP 8.1+.

## Code conventions

- **Namespace**: `LaravelAnalyzer\` maps to `src/`
- **No composer packages**: all utilities must be in pure PHP
- **Analyzer contract**: `analyze(string $projectPath): array` must return `score`, `metrics`, `issues`, `recommendations`
- **Risk values**: `LOW`, `MEDIUM`, `HIGH`, `CRITICAL` (not Spanish variants)
- **Strings**: all user-facing text in English

## Architecture

```
bin/laravel-analyze       → CLI entry point
bin/laravel-analyze-mcp   → MCP server entry point
src/Console/Application.php        → CLI orchestration
src/Analyzers/BaseAnalyzer.php     → shared utilities
src/Analyzers/*Analyzer.php        → 6 analysis modules
src/Reports/ReportGenerator.php    → console/json/html/markdown output
src/Mcp/Server.php                 → JSON-RPC 2.0 server loop
src/Mcp/ToolHandler.php            → MCP tools
src/Mcp/ResourceHandler.php        → MCP resources
src/Mcp/PromptHandler.php          → MCP prompts
```

## When suggesting changes

- Keep analyzers independent — no cross-analyzer imports
- MCP STDOUT = JSON-RPC only; use STDERR for logs
- Score is always an integer 0–100
- Issues array: `[{ severity, file, message }]`
- Recommendations array: `["..."]`

## Running the tool

```bash
php bin/laravel-analyze /path/to/project --format=json
php bin/laravel-analyze-mcp /path/to/project   # MCP server
```
