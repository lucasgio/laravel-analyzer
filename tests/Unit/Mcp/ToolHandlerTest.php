<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Mcp;

use LaravelAnalyzer\Mcp\ToolHandler;
use PHPUnit\Framework\TestCase;

class ToolHandlerTest extends TestCase
{
    private string $fixturePath;
    private ToolHandler $handler;

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 2) . '/Fixtures/LaravelProject';
        $this->handler = new ToolHandler($this->fixturePath);
    }

    public function testListReturnsToolsKey(): void
    {
        $result = $this->handler->list();
        $this->assertArrayHasKey('tools', $result);
    }

    public function testListReturnsFourTools(): void
    {
        $result = $this->handler->list();
        $this->assertCount(4, $result['tools']);
    }

    public function testToolNamesAreCorrect(): void
    {
        $result = $this->handler->list();
        $names = array_column($result['tools'], 'name');
        $this->assertContains('analyze', $names);
        $this->assertContains('analyze_module', $names);
        $this->assertContains('get_issues', $names);
        $this->assertContains('get_recommendations', $names);
    }

    public function testEachToolHasRequiredStructure(): void
    {
        $result = $this->handler->list();
        foreach ($result['tools'] as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
        }
    }

    public function testAnalyzeModuleToolRequiresModuleParam(): void
    {
        $result = $this->handler->list();
        $analyzeModule = null;
        foreach ($result['tools'] as $tool) {
            if ($tool['name'] === 'analyze_module') {
                $analyzeModule = $tool;
                break;
            }
        }
        $this->assertNotNull($analyzeModule);
        $this->assertArrayHasKey('required', $analyzeModule['inputSchema']);
        $this->assertContains('module', $analyzeModule['inputSchema']['required']);
    }

    public function testCallUnknownToolThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->handler->call(['name' => 'nonexistent_tool', 'arguments' => []]);
    }

    public function testCallAnalyzeReturnsTextContent(): void
    {
        $result = $this->handler->call(['name' => 'analyze', 'arguments' => []]);

        $this->assertArrayHasKey('content', $result);
        $this->assertSame('text', $result['content'][0]['type']);
    }

    public function testCallAnalyzeReturnsValidJson(): void
    {
        $result = $this->handler->call(['name' => 'analyze', 'arguments' => []]);
        $data = json_decode($result['content'][0]['text'], true);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('global_score', $data);
        $this->assertArrayHasKey('grade', $data);
        $this->assertArrayHasKey('results', $data);
    }

    public function testCallAnalyzeWithSubsetModules(): void
    {
        $result = $this->handler->call([
            'name' => 'analyze',
            'arguments' => ['modules' => ['security']],
        ]);
        $data = json_decode($result['content'][0]['text'], true);

        $this->assertArrayHasKey('security', $data['results']);
        $this->assertArrayNotHasKey('coupling', $data['results']);
    }

    public function testCallAnalyzeModuleReturnsModuleResult(): void
    {
        $result = $this->handler->call([
            'name' => 'analyze_module',
            'arguments' => ['module' => 'security'],
        ]);
        $data = json_decode($result['content'][0]['text'], true);

        $this->assertSame('security', $data['module']);
        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('risk', $data);
    }

    public function testCallAnalyzeModuleUnknownModuleThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->handler->call([
            'name' => 'analyze_module',
            'arguments' => ['module' => 'nonexistent'],
        ]);
    }

    public function testCallGetIssuesReturnsIssueList(): void
    {
        $result = $this->handler->call([
            'name' => 'get_issues',
            'arguments' => ['severity' => 'HIGH'],
        ]);
        $data = json_decode($result['content'][0]['text'], true);

        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('issues', $data);
        $this->assertArrayHasKey('filter_applied', $data);
    }

    public function testCallGetIssuesSortsHighBeforeLow(): void
    {
        $result = $this->handler->call([
            'name' => 'get_issues',
            'arguments' => ['severity' => 'LOW'],
        ]);
        $data = json_decode($result['content'][0]['text'], true);

        if (count($data['issues']) >= 2) {
            $severityOrder = ['CRITICAL' => 4, 'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
            for ($i = 0; $i < count($data['issues']) - 1; $i++) {
                $current = $severityOrder[$data['issues'][$i]['severity']] ?? 0;
                $next = $severityOrder[$data['issues'][$i + 1]['severity']] ?? 0;
                $this->assertGreaterThanOrEqual($next, $current);
            }
        }

        $this->assertTrue(true); // Assert no exceptions
    }

    public function testCallGetRecommendationsReturnsRecommendations(): void
    {
        $result = $this->handler->call([
            'name' => 'get_recommendations',
            'arguments' => [],
        ]);
        $data = json_decode($result['content'][0]['text'], true);

        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('recommendations', $data);
        $this->assertArrayHasKey('by_module', $data);
    }

    public function testCallGetRecommendationsFiltersByModule(): void
    {
        $result = $this->handler->call([
            'name' => 'get_recommendations',
            'arguments' => ['module' => 'security'],
        ]);
        $data = json_decode($result['content'][0]['text'], true);

        $this->assertArrayHasKey('security', $data['by_module']);
        $this->assertArrayNotHasKey('coupling', $data['by_module']);
    }
}
