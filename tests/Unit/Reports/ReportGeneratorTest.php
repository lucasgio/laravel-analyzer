<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Reports;

use LaravelAnalyzer\Reports\ReportGenerator;
use PHPUnit\Framework\TestCase;

class ReportGeneratorTest extends TestCase
{
    private function makeResults(array $overrides = []): array
    {
        $base = [
            'coupling' => [
                'score' => 80.0,
                'risk' => 'LOW',
                'summary' => 'Coupling summary',
                'metrics' => ['total_classes' => 5, 'god_classes' => 0],
                'issues' => [],
                'recommendations' => ['Rec 1', 'Rec 2'],
            ],
            'testing' => [
                'score' => 60.0,
                'risk' => 'MEDIUM',
                'summary' => 'Testing summary',
                'metrics' => ['unit_tests' => 5],
                'issues' => [['severity' => 'HIGH', 'file' => 'tests/', 'line' => 0, 'message' => 'Low coverage']],
                'recommendations' => [],
            ],
            'security' => [
                'score' => 40.0,
                'risk' => 'HIGH',
                'summary' => 'Security summary',
                'metrics' => ['total_vulnerabilities' => 3, 'critical' => 1],
                'issues' => [['severity' => 'CRITICAL', 'file' => 'app/Vuln.php', 'line' => 5, 'message' => 'SQL injection']],
                'recommendations' => ['Fix SQL injection'],
            ],
        ];

        return array_merge($base, $overrides);
    }

    private function makeGenerator(array $results = [], array $options = [], string $path = '/var/www/myapp'): ReportGenerator
    {
        return new ReportGenerator($results ?: $this->makeResults(), $options, $path);
    }

    // ── JSON ──────────────────────────────────────────

    public function testToJsonReturnsValidJson(): void
    {
        $json = $this->makeGenerator()->toJson();
        $this->assertNotNull(json_decode($json));
    }

    public function testToJsonHasRequiredKeys(): void
    {
        $data = json_decode($this->makeGenerator()->toJson(), true);

        $this->assertArrayHasKey('generated_at', $data);
        $this->assertArrayHasKey('project', $data);
        $this->assertArrayHasKey('project_path', $data);
        $this->assertArrayHasKey('global_score', $data);
        $this->assertArrayHasKey('grade', $data);
        $this->assertArrayHasKey('analyses', $data);
    }

    public function testToJsonGlobalScoreIsAverage(): void
    {
        $data = json_decode($this->makeGenerator()->toJson(), true);

        // avg(80, 60, 40) = 60.0
        $this->assertEqualsWithDelta(60.0, $data['global_score'], 0.1);
    }

    public function testToJsonGradeAPlus(): void
    {
        $results = ['x' => ['score' => 95.0, 'risk' => 'LOW', 'summary' => '', 'metrics' => [], 'issues' => [], 'recommendations' => []]];
        $data = json_decode($this->makeGenerator($results)->toJson(), true);
        $this->assertSame('A+', $data['grade']);
    }

    public function testToJsonGradeA(): void
    {
        $results = ['x' => ['score' => 85.0, 'risk' => 'LOW', 'summary' => '', 'metrics' => [], 'issues' => [], 'recommendations' => []]];
        $data = json_decode($this->makeGenerator($results)->toJson(), true);
        $this->assertSame('A', $data['grade']);
    }

    public function testToJsonGradeF(): void
    {
        $results = ['x' => ['score' => 30.0, 'risk' => 'CRITICAL', 'summary' => '', 'metrics' => [], 'issues' => [], 'recommendations' => []]];
        $data = json_decode($this->makeGenerator($results)->toJson(), true);
        $this->assertSame('F', $data['grade']);
    }

    public function testToJsonEmptyResultsScoreZero(): void
    {
        $gen = new ReportGenerator([], [], '/var/www/myapp');
        $data = json_decode($gen->toJson(), true);
        $this->assertSame(0.0, (float)$data['global_score']);
    }

    public function testToJsonProjectNameFromPath(): void
    {
        $gen = new ReportGenerator([], [], '/var/www/my-laravel-app');
        $data = json_decode($gen->toJson(), true);
        $this->assertSame('my-laravel-app', $data['project']);
    }

    // ── MARKDOWN ─────────────────────────────────────

    public function testToMarkdownContainsTitle(): void
    {
        $md = $this->makeGenerator()->toMarkdown();
        $this->assertStringContainsString('# 🔍 Laravel Best Practices Analysis Report', $md);
    }

    public function testToMarkdownContainsSummaryTable(): void
    {
        $md = $this->makeGenerator()->toMarkdown();
        $this->assertStringContainsString('| Module | Score | Risk | Critical Issues |', $md);
    }

    public function testToMarkdownContainsCriticalIssues(): void
    {
        $md = $this->makeGenerator()->toMarkdown();
        $this->assertStringContainsString('Critical/High Issues', $md);
    }

    public function testToMarkdownSkipsResultsWithError(): void
    {
        $results = array_merge($this->makeResults(), [
            'broken' => ['error' => 'Something failed', 'score' => 0],
        ]);
        $md = $this->makeGenerator($results)->toMarkdown();
        // 'broken' key should not appear in summary table
        $this->assertStringNotContainsString('broken', $md);
    }

    // ── HTML ─────────────────────────────────────────

    public function testToHtmlReturnsValidHtml(): void
    {
        $html = $this->makeGenerator()->toHtml();
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testToHtmlEscapesIssueMessages(): void
    {
        $results = [
            'security' => [
                'score' => 50.0,
                'risk' => 'MEDIUM',
                'summary' => '',
                'metrics' => [],
                'issues' => [['severity' => 'CRITICAL', 'file' => 'app/Foo.php', 'line' => 1, 'message' => '<script>alert(1)</script>']],
                'recommendations' => [],
            ],
        ];
        $html = $this->makeGenerator($results)->toHtml();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testToHtmlHighScoreUsesGreenColor(): void
    {
        $results = ['x' => ['score' => 90.0, 'risk' => 'LOW', 'summary' => '', 'metrics' => [], 'issues' => [], 'recommendations' => []]];
        $html = $this->makeGenerator($results)->toHtml();
        $this->assertStringContainsString('#22c55e', $html);
    }

    public function testToHtmlLowScoreUsesRedColor(): void
    {
        $results = ['x' => ['score' => 30.0, 'risk' => 'CRITICAL', 'summary' => '', 'metrics' => [], 'issues' => [], 'recommendations' => []]];
        $html = $this->makeGenerator($results)->toHtml();
        $this->assertStringContainsString('#ef4444', $html);
    }

    // ── CONSOLE ──────────────────────────────────────

    public function testToConsoleDoesNotThrow(): void
    {
        ob_start();
        $this->makeGenerator()->toConsole(true);
        $output = ob_get_clean();
        $this->assertNotEmpty($output);
    }

    public function testToConsoleWithNoColorDoesNotContainAnsiCodes(): void
    {
        ob_start();
        $this->makeGenerator()->toConsole(true);
        $output = ob_get_clean();
        $this->assertStringNotContainsString("\033[", $output);
    }

    public function testToConsoleShowsErrorForBrokenAnalysis(): void
    {
        $results = array_merge($this->makeResults(), [
            'debt' => ['error' => 'Analysis failed', 'score' => 0],
        ]);
        ob_start();
        $this->makeGenerator($results)->toConsole(true);
        $output = ob_get_clean();
        $this->assertStringContainsString('ERROR', $output);
    }

    public function testToConsoleShowsOwaspBreakdown(): void
    {
        $results = [
            'owasp' => [
                'score' => 70.0,
                'risk' => 'MEDIUM',
                'summary' => 'OWASP summary',
                'metrics' => [
                    'owasp_breakdown' => [
                        'A01' => ['name' => 'Broken Access Control', 'score' => 80, 'risk' => 'LOW'],
                        'A02' => ['name' => 'Cryptographic Failures', 'score' => 60, 'risk' => 'MEDIUM'],
                    ],
                ],
                'issues' => [],
                'recommendations' => [],
            ],
        ];
        ob_start();
        $this->makeGenerator($results)->toConsole(true);
        $output = ob_get_clean();
        $this->assertStringContainsString('A01', $output);
    }
}
