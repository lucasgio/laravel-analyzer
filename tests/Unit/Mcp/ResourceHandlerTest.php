<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Mcp;

use LaravelAnalyzer\Mcp\ResourceHandler;
use PHPUnit\Framework\TestCase;

class ResourceHandlerTest extends TestCase
{
    private ResourceHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ResourceHandler();
    }

    public function testListReturnsResourcesKey(): void
    {
        $result = $this->handler->list();
        $this->assertArrayHasKey('resources', $result);
    }

    public function testListReturnsSixResources(): void
    {
        $result = $this->handler->list();
        $this->assertCount(6, $result['resources']);
    }

    public function testEachResourceHasRequiredFields(): void
    {
        $result = $this->handler->list();
        foreach ($result['resources'] as $resource) {
            $this->assertArrayHasKey('uri', $resource);
            $this->assertArrayHasKey('name', $resource);
            $this->assertArrayHasKey('description', $resource);
            $this->assertArrayHasKey('mimeType', $resource);
        }
    }

    public function testAllResourceUrisStartWithScheme(): void
    {
        $result = $this->handler->list();
        foreach ($result['resources'] as $resource) {
            $this->assertStringStartsWith('laravel-analyzer://docs', $resource['uri']);
        }
    }

    public function testAllMimeTypesAreMarkdown(): void
    {
        $result = $this->handler->list();
        foreach ($result['resources'] as $resource) {
            $this->assertSame('text/markdown', $resource['mimeType']);
        }
    }

    public function testReadOverviewReturnsContent(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/overview']);
        $this->assertArrayHasKey('contents', $result);
        $this->assertNotEmpty($result['contents'][0]['text']);
    }

    public function testReadOverviewContainsQuickStart(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/overview']);
        $this->assertStringContainsString('laravel-analyze', $result['contents'][0]['text']);
    }

    public function testReadModulesContainsAllModuleNames(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/modules']);
        $text = $result['contents'][0]['text'];
        $this->assertStringContainsString('coupling', strtolower($text));
        $this->assertStringContainsString('security', strtolower($text));
        $this->assertStringContainsString('owasp', strtolower($text));
    }

    public function testReadModuleCouplingContainsGodClass(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/module/coupling']);
        $this->assertStringContainsString('God Class', $result['contents'][0]['text']);
    }

    public function testReadModuleSecurityContainsSqlInjection(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/module/security']);
        $this->assertStringContainsString('SQL', $result['contents'][0]['text']);
    }

    public function testReadModuleOwaspContainsA01(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/module/owasp']);
        $this->assertStringContainsString('A01', $result['contents'][0]['text']);
    }

    public function testReadScoresContainsGrade(): void
    {
        $result = $this->handler->read(['uri' => 'laravel-analyzer://docs/scores']);
        $this->assertStringContainsString('Grade', $result['contents'][0]['text']);
    }

    public function testReadUnknownUriThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->handler->read(['uri' => 'laravel-analyzer://docs/nonexistent']);
    }
}
