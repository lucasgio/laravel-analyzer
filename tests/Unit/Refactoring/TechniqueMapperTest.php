<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Refactoring;

use LaravelAnalyzer\Refactoring\TechniqueMapper;
use PHPUnit\Framework\TestCase;

class TechniqueMapperTest extends TestCase
{
    private TechniqueMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TechniqueMapper();
    }

    private function makeIssue(string $message, string $severity = 'HIGH'): array
    {
        return [
            'severity' => $severity,
            'file'     => 'app/Services/OrderService.php',
            'line'     => 10,
            'message'  => $message,
        ];
    }

    public function testMapReturnsNullForUnknownIssue(): void
    {
        $result = $this->mapper->map($this->makeIssue('Unknown issue pattern XYZ'));
        $this->assertNull($result);
    }

    public function testMapMatchesHighCyclomaticComplexity(): void
    {
        $result = $this->mapper->map($this->makeIssue('High Cyclomatic Complexity detected in method'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Method', $result['name']);
        $this->assertSame('extract-method', $result['branch_slug']);
    }

    public function testMapMatchesDeepNesting(): void
    {
        $result = $this->mapper->map($this->makeIssue('deep nesting level 5 detected'));
        $this->assertNotNull($result);
        $this->assertSame('Decompose Conditional', $result['name']);
    }

    public function testMapMatchesDuplicateCode(): void
    {
        $result = $this->mapper->map($this->makeIssue('duplicate code found across files'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Method + Form Template Method', $result['name']);
    }

    public function testMapMatchesGodClass(): void
    {
        $result = $this->mapper->map($this->makeIssue('God Class detected: too many responsibilities'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Class', $result['name']);
        $this->assertSame('HIGH', $result['risk']);
    }

    public function testMapMatchesHighCoupling(): void
    {
        $result = $this->mapper->map($this->makeIssue('high coupling: depends on 8 classes'));
        $this->assertNotNull($result);
        $this->assertSame('Move Method / Introduce Parameter Object', $result['name']);
    }

    public function testMapMatchesSrpViolation(): void
    {
        $result = $this->mapper->map($this->makeIssue('[SRP] Class mixes DB, HTTP, Mail responsibilities'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Class + Move Method', $result['name']);
        $this->assertSame('srp-extract-class', $result['branch_slug']);
    }

    public function testMapMatchesOcpViolation(): void
    {
        $result = $this->mapper->map($this->makeIssue('[OCP] Switch statement dispatching on type'));
        $this->assertNotNull($result);
        $this->assertSame('Replace Conditional with Polymorphism', $result['name']);
    }

    public function testMapMatchesDipViolation(): void
    {
        $result = $this->mapper->map($this->makeIssue('[DIP] Injects 3 concrete classes without interfaces'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Interface', $result['name']);
    }

    public function testMapMatchesDiViolation(): void
    {
        $result = $this->mapper->map($this->makeIssue('[DI] Uses new ClassName() inside method body'));
        $this->assertNotNull($result);
        $this->assertSame('Replace Constructor with Dependency Injection', $result['name']);
    }

    public function testMapMatchesActionsViolation(): void
    {
        $result = $this->mapper->map($this->makeIssue('[Actions] Fat controller, consider extracting Action class'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Class → Action', $result['name']);
        $this->assertSame('LOW', $result['risk']);
    }

    public function testMapMatchesSqlInjection(): void
    {
        $result = $this->mapper->map($this->makeIssue('SQL injection risk: raw query with user input'));
        $this->assertNotNull($result);
        $this->assertSame('Substitute Algorithm — Parameterized Queries', $result['name']);
        $this->assertSame('HIGH', $result['risk']);
    }

    public function testMapMatchesWeakHash(): void
    {
        $result = $this->mapper->map($this->makeIssue('weak hash: MD5 used for password'));
        $this->assertNotNull($result);
        $this->assertSame('Substitute Algorithm — Secure Hashing', $result['name']);
    }

    public function testMapMatchesMassAssignment(): void
    {
        $result = $this->mapper->map($this->makeIssue('mass assignment: $guarded = []'));
        $this->assertNotNull($result);
        $this->assertSame('Substitute Algorithm — Explicit Assignment', $result['name']);
    }

    public function testMapMatchesSessionFixation(): void
    {
        $result = $this->mapper->map($this->makeIssue('session fixation: missing session regeneration'));
        $this->assertNotNull($result);
        $this->assertSame('Substitute Algorithm — Session Regeneration', $result['name']);
    }

    public function testMapMatchesTechnicalDebt(): void
    {
        $result = $this->mapper->map($this->makeIssue('Technical Debt: 5 TODO comments'));
        $this->assertNotNull($result);
        $this->assertSame('Remove Dead Code / Inline Method', $result['name']);
    }

    public function testMapIsCaseInsensitive(): void
    {
        $result = $this->mapper->map($this->makeIssue('GOD CLASS with too many methods'));
        $this->assertNotNull($result);
        $this->assertSame('Extract Class', $result['name']);
    }

    public function testTechniqueHasRequiredKeys(): void
    {
        $result = $this->mapper->map($this->makeIssue('High Cyclomatic Complexity'));
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('steps', $result);
        $this->assertArrayHasKey('test_hint', $result);
        $this->assertArrayHasKey('branch_slug', $result);
        $this->assertArrayHasKey('risk', $result);
        $this->assertIsArray($result['steps']);
        $this->assertNotEmpty($result['steps']);
    }

    public function testMapAllReturnsEmptyForNoIssues(): void
    {
        $result = $this->mapper->mapAll([]);
        $this->assertSame([], $result);
    }

    public function testMapAllReturnsPairsForMatchedIssues(): void
    {
        $issues = [
            $this->makeIssue('High Cyclomatic Complexity'),
            $this->makeIssue('Unknown XYZ'),
            $this->makeIssue('God Class detected'),
        ];

        $pairs = $this->mapper->mapAll($issues);

        $this->assertCount(2, $pairs);
        $this->assertArrayHasKey('issue', $pairs[0]);
        $this->assertArrayHasKey('technique', $pairs[0]);
        $this->assertSame('Extract Method', $pairs[0]['technique']['name']);
        $this->assertSame('Extract Class', $pairs[1]['technique']['name']);
    }

    public function testMapAllSkipsUnmappedIssues(): void
    {
        $issues = [
            $this->makeIssue('Something completely unknown'),
            $this->makeIssue('Another mystery'),
        ];

        $pairs = $this->mapper->mapAll($issues);
        $this->assertCount(0, $pairs);
    }
}
