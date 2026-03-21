<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Mcp;

/**
 * MCP PromptHandler — predefined prompt templates surfaced as slash commands.
 *
 * Prompts (user-driven, client surfaces as slash commands):
 *   /mcp__laravel-analyzer__security-audit   → guided security + OWASP analysis
 *   /mcp__laravel-analyzer__pre-commit-check → quality gate before committing
 *   /mcp__laravel-analyzer__full-review      → comprehensive analysis with action plan
 */
class PromptHandler
{
    private string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
    }

    public function list(): array
    {
        return [
            'prompts' => [
                [
                    'name'        => 'security-audit',
                    'description' => 'Run a security audit on the Laravel project. '
                        . 'Analyzes both Laravel-specific vulnerabilities and OWASP Top 10 compliance, '
                        . 'then provides a prioritized remediation plan.',
                    'arguments'   => [
                        [
                            'name'        => 'project_path',
                            'description' => 'Absolute path to the Laravel project root. Defaults to the configured path.',
                            'required'    => false,
                        ],
                    ],
                ],
                [
                    'name'        => 'pre-commit-check',
                    'description' => 'Quality gate check to run before committing. '
                        . 'Runs the full analysis suite and reports whether the project meets '
                        . 'the minimum quality threshold.',
                    'arguments'   => [
                        [
                            'name'        => 'project_path',
                            'description' => 'Absolute path to the Laravel project root. Defaults to the configured path.',
                            'required'    => false,
                        ],
                        [
                            'name'        => 'threshold',
                            'description' => 'Minimum score to pass (0-100). Default: 60.',
                            'required'    => false,
                        ],
                    ],
                ],
                [
                    'name'        => 'full-review',
                    'description' => 'Comprehensive code review across all 6 analysis modules. '
                        . 'Returns a complete quality assessment with a prioritized action plan '
                        . 'organized by impact and effort.',
                    'arguments'   => [
                        [
                            'name'        => 'project_path',
                            'description' => 'Absolute path to the Laravel project root. Defaults to the configured path.',
                            'required'    => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function get(array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        $projectPath = $args['project_path'] ?? $this->projectPath;

        $messages = match ($name) {
            'security-audit'   => $this->promptSecurityAudit($projectPath),
            'pre-commit-check' => $this->promptPreCommitCheck($projectPath, $args),
            'full-review'      => $this->promptFullReview($projectPath),
            default            => throw new \RuntimeException("Prompt not found: {$name}"),
        };

        return [
            'description' => $this->getDescription($name),
            'messages'    => $messages,
        ];
    }

    // ─────────────────────────────────────────
    // PROMPT CONTENT
    // ─────────────────────────────────────────

    private function promptSecurityAudit(string $projectPath): array
    {
        $instructions = <<<TEXT
You are performing a security audit on a Laravel project.

**Project path**: {$projectPath}

Follow these steps in order:

## Step 1 — Run Security Analysis
Call the `analyze_module` tool with:
- `module`: "security"
- `project_path`: "{$projectPath}"

## Step 2 — Run OWASP Compliance Check
Call the `analyze_module` tool with:
- `module`: "owasp"
- `project_path`: "{$projectPath}"

## Step 3 — Retrieve Critical Issues
Call the `get_issues` tool with:
- `severity`: "HIGH"
- `project_path`: "{$projectPath}"

## Step 4 — Produce Audit Report
After collecting the results, write a security audit report with the following structure:

### Security Audit Report
**Overall Security Score**: [average of security + owasp scores]/100
**Risk Level**: [derived from score]

#### Critical Findings
List all CRITICAL and HIGH severity issues with:
- The vulnerability type
- The affected file and line (if available)
- Why it is dangerous
- A concrete fix with code example

#### OWASP Top 10 Coverage
For each OWASP category that has issues, explain:
- What was found
- The risk to the application
- Recommended remediation

#### Remediation Priority
Order fixes by: impact × exploitability. For each item:
1. [Priority] — [Issue] → [Fix]

#### Quick Wins
List 3-5 fixes that take under 30 minutes and have high impact.

Be specific. Reference actual files and patterns found. Do not give generic advice.
TEXT;

        return [
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => $instructions]],
        ];
    }

    private function promptPreCommitCheck(string $projectPath, array $args): array
    {
        $threshold   = (int)($args['threshold'] ?? 60);

        $instructions = <<<TEXT
You are acting as a quality gate before a git commit. Run the full analysis and determine if the code is ready to commit.

**Project path**: {$projectPath}
**Minimum threshold**: {$threshold}/100

## Step 1 — Run Full Analysis
Call the `analyze` tool with:
- `project_path`: "{$projectPath}"

## Step 2 — Check Critical Issues
Call the `get_issues` tool with:
- `severity`: "CRITICAL"
- `project_path`: "{$projectPath}"

## Step 3 — Evaluate Results

### PASS criteria (all must be true):
- Global score >= {$threshold}
- Zero CRITICAL severity issues
- Security module score >= 50

### FAIL criteria (any one triggers failure):
- Global score < {$threshold}
- Any CRITICAL severity issue exists
- Security module score < 50

## Step 4 — Report

Output a clear verdict:

---
## Pre-Commit Quality Check

**Status**: ✅ PASS / ❌ FAIL
**Global Score**: X/100 [Grade]
**Threshold**: {$threshold}/100

| Module | Score | Risk |
|--------|-------|------|
| ...    | ...   | ...  |

**Critical Issues**: X found

[If FAIL] **Blocking issues** (must fix before committing):
- [Issue] in [file]: [fix]

[If PASS] **Optional improvements** (non-blocking):
- [Issue]: [suggestion]
---

Be decisive. Do not suggest commits when the check fails.
TEXT;

        return [
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => $instructions]],
        ];
    }

    private function promptFullReview(string $projectPath): array
    {
        $instructions = <<<TEXT
You are performing a comprehensive code review on a Laravel project. Your goal is to produce an actionable quality report that a development team can use to plan their next sprint.

**Project path**: {$projectPath}

## Step 1 — Run All Modules
Call the `analyze` tool with:
- `project_path`: "{$projectPath}"

## Step 2 — Get Top Issues
Call the `get_issues` tool with:
- `severity`: "HIGH"
- `limit`: 30
- `project_path`: "{$projectPath}"

## Step 3 — Get Recommendations
Call the `get_recommendations` tool with:
- `limit`: 20
- `project_path`: "{$projectPath}"

## Step 4 — Produce the Review Report

Structure your report as follows:

---
# Laravel Project Quality Review

## Executive Summary
**Global Score**: X/100 [Grade]
**Risk Level**: LOW / MEDIUM / HIGH / CRITICAL
**Summary**: 2-3 sentence overview of the project's quality state.

## Module Scores
| Module | Score | Grade | Risk | Key Finding |
|--------|-------|-------|------|-------------|
| Coupling & Cohesion | ... | ... | ... | ... |
| Test Coverage       | ... | ... | ... | ... |
| Technical Debt      | ... | ... | ... | ... |
| Complexity          | ... | ... | ... | ... |
| Security            | ... | ... | ... | ... |
| OWASP Top 10        | ... | ... | ... | ... |

## Priority Action Plan

### 🔴 Immediate (Sprint 1) — Critical & High severity
Address these before any new feature work.
- [Issue] → [Specific fix] [Estimated effort: S/M/L]

### 🟡 Short-term (Sprint 2-3) — Medium severity
Plan these in the next 2 sprints.
- [Issue] → [Specific fix]

### 🟢 Long-term (Backlog) — Low severity / improvements
Add to backlog, no urgency.
- [Issue] → [Improvement]

## Security Highlights
Call out any security issues separately, regardless of score — security debt compounds.

## Quick Wins
5 improvements achievable in under 1 hour each with meaningful score impact.

## Strengths
What the project does well — reinforce these practices.
---

Be specific. Reference actual metrics and files. Prioritize ruthlessly — a list of 50 todos is useless; a ranked list of 10 is actionable.
TEXT;

        return [
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => $instructions]],
        ];
    }

    // ─────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────

    private function getDescription(string $name): string
    {
        return match ($name) {
            'security-audit'   => 'Security audit: Laravel vulnerabilities + OWASP Top 10 compliance with remediation plan.',
            'pre-commit-check' => 'Pre-commit quality gate: full analysis against a minimum score threshold.',
            'full-review'      => 'Comprehensive quality review across all 6 modules with a prioritized action plan.',
            default            => '',
        };
    }
}
