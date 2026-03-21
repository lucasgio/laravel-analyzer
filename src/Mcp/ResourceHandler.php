<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Mcp;

/**
 * MCP ResourceHandler — exposes static documentation as readable URIs.
 *
 * Resources (application-driven, client decides when to include):
 *   laravel-analyzer://docs/overview     → tool overview + quick-start
 *   laravel-analyzer://docs/modules      → all 6 module descriptions
 *   laravel-analyzer://docs/module/{key} → single module deep-dive
 *   laravel-analyzer://docs/owasp        → OWASP Top 10 mapping
 *   laravel-analyzer://docs/scores       → score / grade interpretation
 */
class ResourceHandler
{
    private const BASE_URI = 'laravel-analyzer://docs';

    public function list(): array
    {
        return [
            'resources' => [
                [
                    'uri'         => self::BASE_URI . '/overview',
                    'name'        => 'Laravel Analyzer — Overview',
                    'description' => 'Tool purpose, quick-start commands, and output format reference.',
                    'mimeType'    => 'text/markdown',
                ],
                [
                    'uri'         => self::BASE_URI . '/modules',
                    'name'        => 'Analysis Modules',
                    'description' => 'Description of all 6 analysis modules, what each detects, and their metrics.',
                    'mimeType'    => 'text/markdown',
                ],
                [
                    'uri'         => self::BASE_URI . '/module/coupling',
                    'name'        => 'Module: Coupling & Cohesion',
                    'description' => 'God Classes, high coupling, SRP violations, long methods.',
                    'mimeType'    => 'text/markdown',
                ],
                [
                    'uri'         => self::BASE_URI . '/module/security',
                    'name'        => 'Module: Laravel Security',
                    'description' => 'SQL Injection, Mass Assignment, XSS, Command Injection, weak hashing.',
                    'mimeType'    => 'text/markdown',
                ],
                [
                    'uri'         => self::BASE_URI . '/module/owasp',
                    'name'        => 'Module: OWASP Top 10',
                    'description' => 'Full OWASP Top 10 (2021) coverage and per-category scoring.',
                    'mimeType'    => 'text/markdown',
                ],
                [
                    'uri'         => self::BASE_URI . '/scores',
                    'name'        => 'Score & Grade Interpretation',
                    'description' => 'How to interpret scores (0-100), grades (A+ to F), and risk levels.',
                    'mimeType'    => 'text/markdown',
                ],
            ],
        ];
    }

    public function read(array $params): array
    {
        $uri = $params['uri'] ?? '';

        $content = match (true) {
            $uri === self::BASE_URI . '/overview'          => $this->docOverview(),
            $uri === self::BASE_URI . '/modules'           => $this->docModules(),
            $uri === self::BASE_URI . '/module/coupling'   => $this->docModuleCoupling(),
            $uri === self::BASE_URI . '/module/testing'    => $this->docModuleTesting(),
            $uri === self::BASE_URI . '/module/debt'       => $this->docModuleDebt(),
            $uri === self::BASE_URI . '/module/complexity' => $this->docModuleComplexity(),
            $uri === self::BASE_URI . '/module/security'   => $this->docModuleSecurity(),
            $uri === self::BASE_URI . '/module/owasp'      => $this->docModuleOwasp(),
            $uri === self::BASE_URI . '/scores'            => $this->docScores(),
            default => throw new \RuntimeException("Resource not found: {$uri}"),
        };

        return [
            'contents' => [
                ['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $content],
            ],
        ];
    }

    // ─────────────────────────────────────────
    // RESOURCE CONTENT
    // ─────────────────────────────────────────

    private function docOverview(): string
    {
        return <<<'MD'
        # Laravel Best Practices Analyzer

        Static analysis CLI for Laravel projects. Zero external dependencies — pure PHP 8.1+.

        ## Quick Start

        ```bash
        # Full analysis (console output)
        php bin/laravel-analyze /path/to/project

        # JSON output (ideal for agents)
        php bin/laravel-analyze /path/to/project --format=json

        # Single module
        php bin/laravel-analyze /path/to/project --only=security

        # Quality gate (exits 1 if score < 75)
        php bin/laravel-analyze /path/to/project --threshold=75
        ```

        ## MCP Tools Available
        | Tool | Description |
        |------|-------------|
        | `analyze` | Full analysis, all or selected modules |
        | `analyze_module` | Single module deep-dive |
        | `get_issues` | Filtered issues by severity/module |
        | `get_recommendations` | Prioritized recommendations |

        ## Output Formats
        `console` (default) · `json` · `html` · `markdown`
        MD;
    }

    private function docModules(): string
    {
        return <<<'MD'
        # Analysis Modules

        | Key | Name | What it detects |
        |-----|------|-----------------|
        | `coupling` | Coupling & Cohesion | God Classes, high coupling, SRP violations, long methods |
        | `testing` | Test Coverage | Unit/feature test ratio, clover.xml coverage, test quality |
        | `debt` | Technical Debt | TODO/FIXME/HACK, anti-patterns, composer issues, env config |
        | `complexity` | Refactoring Complexity | Cyclomatic complexity, deep nesting, code duplication |
        | `security` | Laravel Security | SQL Injection, Mass Assignment, XSS, weak hashing, open redirect |
        | `owasp` | OWASP Top 10 | A01-A10 (2021), per-category scoring and findings |

        Each module returns:
        ```json
        {
          "score": 0-100,
          "risk": "LOW|MEDIUM|HIGH|CRITICAL",
          "summary": "...",
          "metrics": { ... },
          "issues": [ { "severity": "...", "file": "...", "message": "..." } ],
          "recommendations": [ "..." ]
        }
        ```
        MD;
    }

    private function docModuleCoupling(): string
    {
        return <<<'MD'
        # Module: Coupling & Cohesion

        Detects structural quality problems at the class level.

        ## What it measures
        - **Coupling**: number of `use` statements + constructor-injected dependencies + static calls
        - **Cohesion**: ratio of `$this->` usage to method count
        - **God Classes**: > 20 methods OR > 500 lines
        - **Long Methods**: > 50 lines per method

        ## Scoring
        `score = (couplingScore * 0.4) + (cohesionScore * 0.4) - godClassPenalty - longMethodPenalty`

        ## Common fixes
        - Extract God Classes into focused Services
        - Use constructor injection via Laravel's Service Container
        - Apply Interface Segregation Principle
        - Use the Repository pattern for data access
        MD;
    }

    private function docModuleTesting(): string
    {
        return <<<'MD'
        # Module: Test Coverage

        Evaluates the presence and quality of tests.

        ## What it measures
        - Test framework presence (PHPUnit / Pest)
        - Source-to-test file ratio
        - Unit vs Feature test balance
        - Line coverage from `clover.xml` (if present)
        - Assertion density per test
        - Presence of factories and data providers

        ## Generate coverage report
        ```bash
        php artisan test --coverage-clover=coverage.xml
        php bin/laravel-analyze . # auto-detects coverage.xml
        ```

        ## Critical areas checked
        `app/Http/Controllers` · `app/Models` · `app/Services`
        `app/Policies` · `app/Jobs` · `app/Listeners`
        MD;
    }

    private function docModuleDebt(): string
    {
        return <<<'MD'
        # Module: Technical Debt

        Identifies accumulated debt and anti-patterns.

        ## Debt indicators
        | Marker | Severity |
        |--------|----------|
        | `FIXME` | HIGH |
        | `HACK`, `DEPRECATED` | HIGH |
        | `XXX`, `WORKAROUND` | MEDIUM |
        | `TODO`, `TEMP` | LOW |

        ## Laravel anti-patterns detected
        - `$_GET`/`$_POST` superglobals instead of `Request`
        - Route Closures instead of Controllers
        - `sleep()` in production code
        - Direct class instantiation (`new ClassName()`)
        - Wildcard dependency versions (`*`, `@dev`)
        - Missing `composer.lock`
        - `APP_DEBUG=true` in production `.env`
        - Missing `down()` in migrations
        MD;
    }

    private function docModuleComplexity(): string
    {
        return <<<'MD'
        # Module: Refactoring Complexity

        Measures Cyclomatic Complexity (CC) per method.

        ## CC formula
        `CC = 1 + if + elseif + for + foreach + while + case + catch + ?? + ternary + && + ||`

        ## Risk levels
        | CC | Risk | Action |
        |----|------|--------|
        | 1-5 | Low | None needed |
        | 6-10 | Medium | Consider simplification |
        | 11-20 | High | Refactor — hard to test |
        | > 20 | Critical | Priority refactor |

        ## Also detects
        - Deep nesting (> 4 levels) — use guard clauses
        - Long classes (> 300 lines)
        - Duplicate code blocks (6+ line windows)
        MD;
    }

    private function docModuleSecurity(): string
    {
        return <<<'MD'
        # Module: Laravel Security

        Detects security vulnerabilities specific to the Laravel ecosystem.

        ## Vulnerabilities detected
        | Category | Severity |
        |----------|----------|
        | SQL Injection via raw query concatenation | CRITICAL |
        | Mass Assignment via empty `$guarded` or `::create($request->all())` | CRITICAL |
        | XSS via Blade unescaped output `{!! !!}` | HIGH |
        | System command injection with unsanitized variable | CRITICAL |
        | Dynamic code evaluation | CRITICAL |
        | Dynamic file inclusion | CRITICAL |
        | Weak hashing — MD5 / SHA1 | HIGH |
        | Open Redirect via user-supplied URL | HIGH |
        | Debug output in production — dd(), var_dump() | HIGH |

        ## Config checks
        - `APP_DEBUG=true` in production
        - `SESSION_SECURE_COOKIE` not set
        - CORS wildcard in `allowed_origins`
        - Missing API auth package (Sanctum/Passport)
        MD;
    }

    private function docModuleOwasp(): string
    {
        return <<<'MD'
        # Module: OWASP Top 10 (2021)

        Maps findings to the OWASP Top 10 standard categories.

        | Code | Category | What is checked |
        |------|----------|-----------------|
        | A01 | Broken Access Control | Policies, IDOR, unprotected controllers |
        | A02 | Cryptographic Failures | MD5/SHA1, hardcoded secrets, HTTP links, HTTPS enforcement |
        | A03 | Injection | SQL, Command, LDAP, Object injection |
        | A04 | Insecure Design | Rate limiting, validation in store()/update() |
        | A05 | Security Misconfiguration | APP_DEBUG, SameSite cookies, .env in .gitignore |
        | A06 | Vulnerable Components | Wildcard versions, missing composer.lock, outdated PHP |
        | A07 | Auth Failures | Session fixation, missing MFA |
        | A08 | Integrity Failures | Missing CI/CD, unsafe deserialization |
        | A09 | Logging Failures | Missing security event logging, Log Injection risk |
        | A10 | SSRF | HTTP requests with user-supplied URLs |

        Each category is scored 0-100 independently.
        MD;
    }

    private function docScores(): string
    {
        return <<<'MD'
        # Score & Grade Interpretation

        ## Global Score
        Average of all module scores (0-100).

        | Score | Grade | Meaning |
        |-------|-------|---------|
        | 90-100 | A+ | Excellent — production ready |
        | 80-89 | A | Very good — minor improvements |
        | 70-79 | B | Good — some areas to address |
        | 60-69 | C | Acceptable — work needed |
        | 50-59 | D | Low quality — urgent refactoring |
        | < 50 | F | Critical — high risk in production |

        ## Risk Levels (per module)
        | Risk | Score range | Action |
        |------|-------------|--------|
        | LOW | >= 80 | Monitor |
        | MEDIUM | 60-79 | Plan improvements |
        | HIGH | 40-59 | Prioritize this sprint |
        | CRITICAL | < 40 | Block deployment |

        ## Default threshold
        Exit code `1` if global score < threshold (default: 60).
        Override with: `--threshold=75`
        MD;
    }
}
