<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Mcp;

use LaravelAnalyzer\Analyzers\CouplingCohesionAnalyzer;
use LaravelAnalyzer\Analyzers\TestCoverageAnalyzer;
use LaravelAnalyzer\Analyzers\TechnicalDebtAnalyzer;
use LaravelAnalyzer\Analyzers\ComplexityAnalyzer;
use LaravelAnalyzer\Analyzers\SecurityAnalyzer;
use LaravelAnalyzer\Analyzers\OwaspAnalyzer;

/**
 * MCP ToolHandler — exposes the Laravel analyzer as callable MCP tools.
 *
 * Tools (model-driven, agent calls them during reasoning):
 *   - analyze             → full project analysis, all or selected modules
 *   - analyze_module      → single module deep-dive
 *   - get_issues          → filtered issue list (by severity / module)
 *   - get_recommendations → prioritized recommendations
 */
class ToolHandler
{
    private string $projectPath;

    private const ANALYZERS = [
        'coupling'   => CouplingCohesionAnalyzer::class,
        'testing'    => TestCoverageAnalyzer::class,
        'debt'       => TechnicalDebtAnalyzer::class,
        'complexity' => ComplexityAnalyzer::class,
        'security'   => SecurityAnalyzer::class,
        'owasp'      => OwaspAnalyzer::class,
    ];

    private const SEVERITY_WEIGHT = [
        'CRITICAL' => 4,
        'HIGH'     => 3,
        'MEDIUM'   => 2,
        'LOW'      => 1,
    ];

    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
    }

    // ─────────────────────────────────────────
    // MCP: tools/list
    // ─────────────────────────────────────────

    public function list(): array
    {
        return [
            'tools' => [
                $this->defineTool(
                    name: 'analyze',
                    description: 'Run a complete Laravel project analysis across all 6 modules '
                        . '(coupling, testing, debt, complexity, security, owasp). '
                        . 'Returns global score, grade, per-module scores, issues, and recommendations.',
                    properties: [
                        'project_path' => [
                            'type'        => 'string',
                            'description' => 'Absolute path to the Laravel project root. '
                                . 'Defaults to the path configured when starting the server.',
                        ],
                        'modules' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string', 'enum' => array_keys(self::ANALYZERS)],
                            'description' => 'Subset of modules to run. Omit to run all 6.',
                        ],
                    ]
                ),

                $this->defineTool(
                    name: 'analyze_module',
                    description: 'Run analysis for a single module and return its full result: '
                        . 'score, risk level, summary, all metrics, issues, and recommendations.',
                    required: ['module'],
                    properties: [
                        'module' => [
                            'type'        => 'string',
                            'enum'        => array_keys(self::ANALYZERS),
                            'description' => 'The module to analyze.',
                        ],
                        'project_path' => [
                            'type'        => 'string',
                            'description' => 'Absolute path to the Laravel project root.',
                        ],
                    ]
                ),

                $this->defineTool(
                    name: 'get_issues',
                    description: 'Run analysis and return a filtered, sorted list of issues. '
                        . 'Use this to focus on critical problems without the full report noise.',
                    properties: [
                        'severity' => [
                            'type'        => 'string',
                            'enum'        => ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'],
                            'description' => 'Minimum severity level to include. CRITICAL returns only critical issues.',
                        ],
                        'module' => [
                            'type'        => 'string',
                            'enum'        => array_keys(self::ANALYZERS),
                            'description' => 'Filter to a specific module.',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of issues to return. Default: 20.',
                        ],
                        'project_path' => [
                            'type'        => 'string',
                            'description' => 'Absolute path to the Laravel project root.',
                        ],
                    ]
                ),

                $this->defineTool(
                    name: 'get_recommendations',
                    description: 'Run analysis and return prioritized, actionable recommendations '
                        . 'grouped by module. Use this to guide a refactoring or security hardening session.',
                    properties: [
                        'module' => [
                            'type'        => 'string',
                            'enum'        => array_keys(self::ANALYZERS),
                            'description' => 'Filter recommendations to a specific module.',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of recommendations. Default: 10.',
                        ],
                        'project_path' => [
                            'type'        => 'string',
                            'description' => 'Absolute path to the Laravel project root.',
                        ],
                    ]
                ),
            ],
        ];
    }

    // ─────────────────────────────────────────
    // MCP: tools/call
    // ─────────────────────────────────────────

    public function call(array $params): array
    {
        $name = $params['name']      ?? '';
        $args = $params['arguments'] ?? [];

        $result = match ($name) {
            'analyze'             => $this->runAnalyze($args),
            'analyze_module'      => $this->runAnalyzeModule($args),
            'get_issues'          => $this->runGetIssues($args),
            'get_recommendations' => $this->runGetRecommendations($args),
            default               => throw new \RuntimeException("Unknown tool: {$name}"),
        };

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────
    // TOOL IMPLEMENTATIONS
    // ─────────────────────────────────────────

    private function runAnalyze(array $args): array
    {
        $projectPath = $args['project_path'] ?? $this->projectPath;
        $modules     = $args['modules']      ?? array_keys(self::ANALYZERS);

        $results = [];
        $scores  = [];
        $errors  = [];

        foreach ($modules as $module) {
            if (!isset(self::ANALYZERS[$module])) continue;

            try {
                $class          = self::ANALYZERS[$module];
                $analyzer       = new $class();
                $result         = $analyzer->analyze($projectPath);
                $results[$module] = $result;
                $scores[]         = $result['score'];
            } catch (\Throwable $e) {
                $errors[$module] = $e->getMessage();
            }
        }

        $globalScore = !empty($scores)
            ? round(array_sum($scores) / count($scores), 1)
            : 0.0;

        return [
            'global_score'  => $globalScore,
            'grade'         => $this->scoreToGrade($globalScore),
            'project_path'  => $projectPath,
            'modules_run'   => $modules,
            'results'       => $results,
            'errors'        => $errors,
            'generated_at'  => date('Y-m-d H:i:s'),
        ];
    }

    private function runAnalyzeModule(array $args): array
    {
        $module      = $args['module']       ?? '';
        $projectPath = $args['project_path'] ?? $this->projectPath;

        if (!isset(self::ANALYZERS[$module])) {
            throw new \RuntimeException(
                "Unknown module: '{$module}'. Valid: " . implode(', ', array_keys(self::ANALYZERS))
            );
        }

        $class    = self::ANALYZERS[$module];
        $analyzer = new $class();

        return array_merge(
            ['module' => $module, 'project_path' => $projectPath],
            $analyzer->analyze($projectPath)
        );
    }

    private function runGetIssues(array $args): array
    {
        $projectPath  = $args['project_path'] ?? $this->projectPath;
        $severity     = strtoupper($args['severity'] ?? 'LOW');
        $moduleFilter = $args['module']        ?? null;
        $limit        = (int)($args['limit']   ?? 20);

        $minWeight = self::SEVERITY_WEIGHT[$severity] ?? 1;

        $analysis  = $this->runAnalyze(['project_path' => $projectPath]);
        $allIssues = [];

        foreach ($analysis['results'] as $module => $result) {
            if ($moduleFilter && $module !== $moduleFilter) continue;

            foreach ($result['issues'] ?? [] as $issue) {
                $weight = self::SEVERITY_WEIGHT[$issue['severity']] ?? 0;
                if ($weight >= $minWeight) {
                    $allIssues[] = array_merge($issue, ['module' => $module]);
                }
            }
        }

        // Sort: CRITICAL → HIGH → MEDIUM → LOW
        usort($allIssues, fn($a, $b) =>
            (self::SEVERITY_WEIGHT[$b['severity']] ?? 0) <=> (self::SEVERITY_WEIGHT[$a['severity']] ?? 0)
        );

        return [
            'total'          => count($allIssues),
            'filter_applied' => ['severity_min' => $severity, 'module' => $moduleFilter],
            'issues'         => array_slice($allIssues, 0, $limit),
        ];
    }

    private function runGetRecommendations(array $args): array
    {
        $projectPath  = $args['project_path'] ?? $this->projectPath;
        $moduleFilter = $args['module']        ?? null;
        $limit        = (int)($args['limit']   ?? 10);

        $analysis = $this->runAnalyze(['project_path' => $projectPath]);
        $grouped  = [];
        $flat     = [];

        foreach ($analysis['results'] as $module => $result) {
            if ($moduleFilter && $module !== $moduleFilter) continue;

            $recs = $result['recommendations'] ?? [];
            if (!empty($recs)) {
                $grouped[$module] = $recs;
                foreach ($recs as $rec) {
                    $flat[] = ['module' => $module, 'recommendation' => $rec];
                }
            }
        }

        return [
            'total'           => count($flat),
            'filter_applied'  => ['module' => $moduleFilter],
            'by_module'       => $grouped,
            'recommendations' => array_slice($flat, 0, $limit),
        ];
    }

    // ─────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────

    private function defineTool(string $name, string $description, array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }

    private function scoreToGrade(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            default      => 'F',
        };
    }
}
