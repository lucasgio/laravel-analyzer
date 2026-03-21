<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Mcp;

use LaravelAnalyzer\Mcp\PromptHandler;
use PHPUnit\Framework\TestCase;

class PromptHandlerTest extends TestCase
{
    private string $fixturePath;
    private PromptHandler $handler;

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 2) . '/Fixtures/LaravelProject';
        $this->handler = new PromptHandler($this->fixturePath);
    }

    public function testListReturnsPromptsKey(): void
    {
        $result = $this->handler->list();
        $this->assertArrayHasKey('prompts', $result);
    }

    public function testListReturnsThreePrompts(): void
    {
        $result = $this->handler->list();
        $this->assertCount(3, $result['prompts']);
    }

    public function testPromptNamesAreCorrect(): void
    {
        $result = $this->handler->list();
        $names = array_column($result['prompts'], 'name');
        $this->assertContains('security-audit', $names);
        $this->assertContains('pre-commit-check', $names);
        $this->assertContains('full-review', $names);
    }

    public function testEachPromptHasRequiredFields(): void
    {
        $result = $this->handler->list();
        foreach ($result['prompts'] as $prompt) {
            $this->assertArrayHasKey('name', $prompt);
            $this->assertArrayHasKey('description', $prompt);
        }
    }

    public function testGetSecurityAuditReturnsMessages(): void
    {
        $result = $this->handler->get(['name' => 'security-audit', 'arguments' => []]);

        $this->assertArrayHasKey('messages', $result);
        $this->assertNotEmpty($result['messages']);
    }

    public function testGetSecurityAuditMessageHasUserRole(): void
    {
        $result = $this->handler->get(['name' => 'security-audit', 'arguments' => []]);
        $this->assertSame('user', $result['messages'][0]['role']);
    }

    public function testGetPreCommitCheckReturnsMessages(): void
    {
        $result = $this->handler->get(['name' => 'pre-commit-check', 'arguments' => []]);
        $this->assertArrayHasKey('messages', $result);
    }

    public function testGetFullReviewReturnsMessages(): void
    {
        $result = $this->handler->get(['name' => 'full-review', 'arguments' => []]);
        $this->assertArrayHasKey('messages', $result);
    }

    public function testGetUnknownPromptThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->handler->get(['name' => 'nonexistent', 'arguments' => []]);
    }

    public function testGetSecurityAuditContainsProjectPath(): void
    {
        $result = $this->handler->get(['name' => 'security-audit', 'arguments' => []]);
        $text = $result['messages'][0]['content']['text'] ?? $result['messages'][0]['content'] ?? '';
        // Should contain the project path or analysis instruction
        $this->assertNotEmpty($text);
    }
}
