<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Integration;

use PHPUnit\Framework\TestCase;

class McpServerProtocolTest extends TestCase
{
    private string $mcpBinPath;
    private string $fixturePath;
    private string $phpBin;

    protected function setUp(): void
    {
        $this->mcpBinPath = dirname(__DIR__, 2) . '/bin/laravel-analyze-mcp';
        $this->fixturePath = dirname(__DIR__) . '/Fixtures/LaravelProject';
        $this->phpBin = PHP_BINARY;

        if (!file_exists($this->mcpBinPath)) {
            $this->markTestSkipped("bin/laravel-analyze-mcp not found");
        }
    }

    private function sendRequest(array $message, int $timeoutMs = 30000): ?array
    {
        $cmd = escapeshellarg($this->phpBin) . ' ' . escapeshellarg($this->mcpBinPath) . ' ' . escapeshellarg($this->fixturePath);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->fail("Failed to start MCP server process");
        }

        // Write request
        $json = json_encode($message) . "\n";
        fwrite($pipes[0], $json);
        fflush($pipes[0]);

        // Read response with timeout
        stream_set_blocking($pipes[1], false);
        $response = '';
        $elapsed = 0;
        $interval = 50000; // 50ms

        while ($elapsed < $timeoutMs * 1000) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;
                if (str_contains($response, "\n")) {
                    break;
                }
            }
            usleep($interval);
            $elapsed += $interval;
        }

        // Close pipes and process
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);

        $line = trim(explode("\n", $response)[0]);
        if (empty($line)) return null;

        return json_decode($line, true);
    }

    public function testInitializeHandshake(): void
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
        $this->assertSame('laravel-analyzer', $response['result']['serverInfo']['name']);
    }

    public function testPing(): void
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'ping',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
    }

    public function testToolsList(): void
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('tools', $response['result']);
        $this->assertCount(4, $response['result']['tools']);
    }

    public function testResourcesList(): void
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'resources/list',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertCount(6, $response['result']['resources']);
    }

    public function testPromptsList(): void
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'prompts/list',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertCount(3, $response['result']['prompts']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'nonexistent/method',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32601, $response['error']['code']);
    }
}
