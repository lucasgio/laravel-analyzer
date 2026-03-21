# Laravel Analyzer — AI Agent Context

Universal context file for AI agent CLIs and IDE AI integrations.
Applies to: Claude Code, Gemini CLI, GitHub Copilot, Cursor, Windsurf, VS Code.

## What This Tool Does

`laravel-analyzer` is a static analysis CLI for Laravel projects. Zero external dependencies — pure PHP 8.1+.

It analyzes 6 quality dimensions and returns scores (0–100), grades (A+ to F), issues, and recommendations.

| Module | Key | What it detects |
|--------|-----|-----------------|
| Coupling & Cohesion | `coupling` | God Classes, high coupling, SRP violations, long methods |
| Test Coverage | `testing` | Test ratio, clover.xml coverage, assertion density |
| Technical Debt | `debt` | TODO/FIXME/HACK, anti-patterns, composer issues |
| Refactoring Complexity | `complexity` | Cyclomatic complexity, deep nesting, duplication |
| Laravel Security | `security` | SQL Injection, Mass Assignment, XSS, command injection |
| OWASP Top 10 | `owasp` | A01–A10 (2021) per-category scoring |

## CLI Usage

```bash
# Full analysis (console output)
php bin/laravel-analyze /path/to/project

# JSON output — best for agent consumption
php bin/laravel-analyze /path/to/project --format=json

# Single module
php bin/laravel-analyze /path/to/project --only=security

# Quality gate (exits 1 if score < threshold)
php bin/laravel-analyze /path/to/project --threshold=75
```

## MCP Server (for AI agents)

```bash
# Start the MCP server
php bin/laravel-analyze-mcp /path/to/project
```

### MCP Tools
| Tool | Description |
|------|-------------|
| `analyze` | Full project analysis, all or selected modules |
| `analyze_module` | Single module deep-dive |
| `get_issues` | Filtered issues by severity/module |
| `get_recommendations` | Prioritized recommendations |

### MCP Prompts (slash commands)
| Prompt | Command in Claude Code |
|--------|------------------------|
| `security-audit` | `/mcp__laravel-analyzer__security-audit` |
| `pre-commit-check` | `/mcp__laravel-analyzer__pre-commit-check` |
| `full-review` | `/mcp__laravel-analyzer__full-review` |

## Score Interpretation

| Score | Grade | Meaning |
|-------|-------|---------|
| 90–100 | A+ | Excellent — production ready |
| 80–89 | A | Very good — minor improvements |
| 70–79 | B | Good — some areas to address |
| 60–69 | C | Acceptable — work needed |
| 50–59 | D | Low quality — urgent refactoring |
| < 50 | F | Critical — high risk in production |

## Risk Levels (per module)

| Risk | Score | Action |
|------|-------|--------|
| LOW | ≥ 80 | Monitor |
| MEDIUM | 60–79 | Plan improvements |
| HIGH | 40–59 | Prioritize this sprint |
| CRITICAL | < 40 | Block deployment |

## Agent Workflow Recommendations

When asked to analyze a Laravel project:
1. Run `analyze` (MCP) or `php bin/laravel-analyze --format=json` (CLI)
2. Start with CRITICAL and HIGH severity issues
3. For security concerns, run `analyze_module` with `security` and `owasp`
4. Use `get_recommendations` for a prioritized remediation list
5. Suggest fixes with concrete code examples — reference the actual files flagged

## Output Structure (JSON)

```json
{
  "global_score": 72.5,
  "grade": "B",
  "modules_run": ["coupling", "testing", "debt", "complexity", "security", "owasp"],
  "results": {
    "security": {
      "score": 45,
      "risk": "HIGH",
      "summary": "...",
      "metrics": {},
      "issues": [{ "severity": "CRITICAL", "file": "...", "message": "..." }],
      "recommendations": ["..."]
    }
  }
}
```
