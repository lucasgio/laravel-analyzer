<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Refactoring;

use LaravelAnalyzer\Refactoring\RefactoringPlanGenerator;
use LaravelAnalyzer\Refactoring\TechniqueMapper;
use PHPUnit\Framework\TestCase;

class RefactoringPlanGeneratorTest extends TestCase
{
    private RefactoringPlanGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RefactoringPlanGenerator();
    }

    private function makeResults(array $issues): array
    {
        return [
            'coupling' => [
                'score'           => 70,
                'metrics'         => [],
                'issues'          => $issues,
                'recommendations' => [],
            ],
        ];
    }

    private function makeIssue(string $message, string $severity = 'HIGH', string $file = 'app/Services/OrderService.php', int $line = 10): array
    {
        return compact('severity', 'file', 'line', 'message');
    }

    public function testGenerateReturnsMarkdownString(): void
    {
        $md = $this->generator->generate([], 'my-project');
        $this->assertIsString($md);
        $this->assertStringContainsString('# 🔧 Refactoring Plan — my-project', $md);
    }

    public function testGenerateIncludesProjectName(): void
    {
        $md = $this->generator->generate([], 'laravel-app');
        $this->assertStringContainsString('laravel-app', $md);
    }

    public function testGenerateWithNoIssuesShowsEmptyMessage(): void
    {
        $md = $this->generator->generate([], 'my-project');
        $this->assertStringContainsString('No refactoring phases generated', $md);
    }

    public function testGenerateWithMappedIssueShowsPhase(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity in doSomething()'),
        ]);

        $md = $this->generator->generate($results, 'my-project');

        $this->assertStringContainsString('## Phase 1:', $md);
        $this->assertStringContainsString('Extract Method', $md);
    }

    public function testGenerateWithOnlyUnmappedIssuesShowsEmptyPhasesMessage(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('Some unknown issue that does not match any technique'),
        ]);

        $md = $this->generator->generate($results, 'my-project');

        $this->assertStringContainsString('No refactoring phases generated', $md);
    }

    public function testGenerateWithMixedIssuesShowsAppendix(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity'),
            $this->makeIssue('Some unknown issue that does not match any technique'),
        ]);

        $md = $this->generator->generate($results, 'my-project');

        $this->assertStringContainsString('Appendix', $md);
        $this->assertStringContainsString('Some unknown issue', $md);
    }

    public function testGenerateSortsCriticalBeforeHigh(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity in methodA()', 'HIGH'),
            $this->makeIssue('God Class: too many responsibilities', 'CRITICAL'),
        ]);

        $md = $this->generator->generate($results, 'my-project');

        $phase1Pos = strpos($md, 'Phase 1:');
        $phase2Pos = strpos($md, 'Phase 2:');
        $criticalPos = strpos($md, 'CRITICAL');
        $highPos = strpos($md, '🟠 HIGH');

        $this->assertLessThan($phase2Pos, $phase1Pos);
        // CRITICAL severity appears before HIGH in the output
        $this->assertLessThan($highPos, $criticalPos);
    }

    public function testGenerateSkipsResultsWithErrors(): void
    {
        $results = [
            'coupling' => ['error' => 'Analysis failed', 'score' => 0],
            'testing'  => [
                'score'           => 80,
                'metrics'         => [],
                'issues'          => [$this->makeIssue('High Cyclomatic Complexity')],
                'recommendations' => [],
            ],
        ];

        $md = $this->generator->generate($results, 'my-project');
        $this->assertStringContainsString('Phase 1:', $md);
    }

    public function testGenerateIncludesHowToUseSection(): void
    {
        $md = $this->generator->generate([], 'my-project');
        $this->assertStringContainsString('How to use this plan', $md);
        $this->assertStringContainsString('Regression Check', $md);
    }

    public function testGenerateIncludesAttributionFooter(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity'),
        ]);

        $md = $this->generator->generate($results, 'my-project');
        $this->assertStringContainsString('laravel-analyzer', $md);
    }

    public function testSuggestBranchReturnsRefactorPrefix(): void
    {
        $issue = $this->makeIssue('High Cyclomatic Complexity');
        $technique = [
            'name'        => 'Extract Method',
            'url'         => 'https://refactoring.guru/extract-method',
            'branch_slug' => 'extract-method',
            'description' => '',
            'steps'       => [],
            'test_hint'   => '',
            'risk'        => 'LOW',
        ];

        $branch = $this->generator->suggestBranch($issue, $technique);
        $this->assertStringStartsWith('refactor/', $branch);
        $this->assertStringContainsString('extract-method', $branch);
    }

    public function testSuggestBranchContainsClassName(): void
    {
        $issue = [
            'severity' => 'HIGH',
            'file'     => 'app/Services/OrderService.php',
            'line'     => 1,
            'message'  => 'test',
        ];
        $technique = [
            'branch_slug' => 'extract-class',
            'name' => 'Extract Class',
            'url' => '',
            'description' => '',
            'steps' => [],
            'test_hint' => '',
            'risk' => 'HIGH',
        ];

        $branch = $this->generator->suggestBranch($issue, $technique);
        $this->assertStringContainsString('order-service', $branch);
    }

    public function testGeneratePhaseIncludesSeverityIcon(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity', 'CRITICAL'),
        ]);

        $md = $this->generator->generate($results, 'my-project');
        $this->assertStringContainsString('🔴', $md);
    }

    public function testGeneratePhaseIncludesTestRequirement(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity'),
        ]);

        $md = $this->generator->generate($results, 'my-project');
        $this->assertStringContainsString('Test Requirement', $md);
        $this->assertStringContainsString('Write this test BEFORE refactoring', $md);
    }

    public function testGeneratePhaseIncludesGitBranch(): void
    {
        $results = $this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity'),
        ]);

        $md = $this->generator->generate($results, 'my-project');
        $this->assertStringContainsString('git checkout -b refactor/', $md);
    }

    public function testGenerateWithMultipleModulesCollectsAllIssues(): void
    {
        $results = [
            'coupling' => [
                'score'           => 70,
                'metrics'         => [],
                'issues'          => [$this->makeIssue('God Class detected')],
                'recommendations' => [],
            ],
            'security' => [
                'score'           => 60,
                'metrics'         => [],
                'issues'          => [$this->makeIssue('SQL injection risk')],
                'recommendations' => [],
            ],
        ];

        $md = $this->generator->generate($results, 'my-project');
        $this->assertStringContainsString('Extract Class', $md);
        $this->assertStringContainsString('Parameterized Queries', $md);
    }

    public function testCustomMapperCanBeInjected(): void
    {
        $mockMapper = $this->createMock(TechniqueMapper::class);
        $mockMapper->method('mapAll')->willReturn([]);

        $generator = new RefactoringPlanGenerator($mockMapper);
        $md = $generator->generate($this->makeResults([
            $this->makeIssue('High Cyclomatic Complexity'),
        ]), 'my-project');

        $this->assertStringContainsString('No refactoring phases generated', $md);
    }
}
