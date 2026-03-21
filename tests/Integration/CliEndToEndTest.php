<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Integration;

use PHPUnit\Framework\TestCase;

class CliEndToEndTest extends TestCase
{
    private string $binPath;
    private string $fixturePath;
    private string $phpBin;

    protected function setUp(): void
    {
        $this->binPath = dirname(__DIR__, 2) . '/bin/laravel-analyze';
        $this->fixturePath = dirname(__DIR__) . '/Fixtures/LaravelProject';
        $this->phpBin = PHP_BINARY;

        if (!file_exists($this->binPath)) {
            $this->markTestSkipped("bin/laravel-analyze not found");
        }
    }

    private function runCli(array $args): array
    {
        $cmd = escapeshellarg($this->phpBin) . ' ' .
               escapeshellarg($this->binPath) . ' ' .
               implode(' ', array_map('escapeshellarg', $args));

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    public function testHelpFlagReturnsZero(): void
    {
        $result = $this->runCli(['--help']);
        $this->assertSame(0, $result['exit_code']);
    }

    public function testHelpFlagShowsUsage(): void
    {
        $result = $this->runCli(['--help']);
        $this->assertStringContainsString('USAGE', $result['output']);
        $this->assertStringContainsString('--format', $result['output']);
    }

    public function testVersionFlagReturnsZero(): void
    {
        $result = $this->runCli(['--version']);
        $this->assertSame(0, $result['exit_code']);
    }

    public function testVersionFlagShowsVersion(): void
    {
        $result = $this->runCli(['--version']);
        $this->assertMatchesRegularExpression('/\d+\.\d+\.\d+/', $result['output']);
    }

    public function testInvalidDirectoryReturnsExitCode1(): void
    {
        $result = $this->runCli(['/nonexistent/path/xyz-abc-123', '--no-color']);
        $this->assertSame(1, $result['exit_code']);
    }

    public function testNonLaravelDirectoryShowsWarning(): void
    {
        $result = $this->runCli([sys_get_temp_dir(), '--no-color']);
        $this->assertStringContainsString('No standard Laravel project detected', $result['output']);
    }

    public function testFullAnalysisOnFixtureOutputsScore(): void
    {
        $result = $this->runCli([$this->fixturePath, '--no-color']);
        $this->assertStringContainsString('GLOBAL PROJECT SCORE', $result['output']);
    }

    public function testFullAnalysisOutputsAllModuleNames(): void
    {
        $result = $this->runCli([$this->fixturePath, '--no-color']);
        $this->assertStringContainsString('Coupling', $result['output']);
        $this->assertStringContainsString('Security', $result['output']);
        $this->assertStringContainsString('OWASP', $result['output']);
    }

    public function testJsonFormatOutputsJsonFile(): void
    {
        $outputFile = sys_get_temp_dir() . '/la-test-' . uniqid() . '.json';
        $result = $this->runCli([$this->fixturePath, '--format=json', '--output=' . $outputFile, '--no-color']);

        $this->assertFileExists($outputFile);
        $data = json_decode(file_get_contents($outputFile), true);
        $this->assertArrayHasKey('global_score', $data);

        unlink($outputFile);
    }

    public function testOnlyFlagRunsOnlySpecifiedModule(): void
    {
        $result = $this->runCli([$this->fixturePath, '--only=security', '--no-color']);

        $this->assertStringContainsString('Security', $result['output']);
        $this->assertStringNotContainsString('Technical Debt', $result['output']);
    }

    public function testThresholdFailCausesExitCode1(): void
    {
        // Fixture project won't score 99
        $result = $this->runCli([$this->fixturePath, '--threshold=99', '--no-color']);
        $this->assertSame(1, $result['exit_code']);
    }

    public function testThresholdFailShowsWarning(): void
    {
        $result = $this->runCli([$this->fixturePath, '--threshold=99', '--no-color']);
        $this->assertStringContainsString('below the minimum quality threshold', $result['output']);
    }

    public function testMarkdownFormatOutputsMarkdownFile(): void
    {
        $outputFile = sys_get_temp_dir() . '/la-test-' . uniqid() . '.md';
        $result = $this->runCli([$this->fixturePath, '--format=markdown', '--output=' . $outputFile, '--no-color']);

        $this->assertFileExists($outputFile);
        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('# 🔍 Laravel Best Practices Analysis Report', $content);

        unlink($outputFile);
    }
}
