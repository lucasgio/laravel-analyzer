<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\BaseAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

// Concrete stub to expose protected methods
class ConcreteAnalyzerStub extends BaseAnalyzer
{
    public function analyze(string $projectPath): array { return []; }

    public function exposeGetPhpFiles(string $path, array $exclude = []): array
    {
        return $exclude ? $this->getPhpFiles($path, $exclude) : $this->getPhpFiles($path);
    }
    public function exposeReadFile(string $path): string { return $this->readFile($path); }
    public function exposeExtractClassName(string $content): ?string { return $this->extractClassName($content); }
    public function exposeExtractNamespace(string $content): ?string { return $this->extractNamespace($content); }
    public function exposeCountLines(string $content): int { return $this->countLines($content); }
    public function exposeScoreToRisk(float $score): string { return $this->scoreToRisk($score); }
    public function exposeBuildResult(float $score, array $metrics, string $summary): array
    {
        return $this->buildResult($score, $metrics, $summary);
    }
    public function exposeAddIssue(string $sev, string $file, string $msg, int $line = 0): void
    {
        $this->addIssue($sev, $file, $msg, $line);
    }
    public function getIssues(): array { return $this->issues; }
    public function getRecommendations(): array { return $this->recommendations; }
}

class BaseAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private ConcreteAnalyzerStub $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new ConcreteAnalyzerStub();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testGetPhpFilesReturnsOnlyPhpFiles(): void
    {
        $this->createFile('app/Foo.php', '<?php class Foo {}');
        $this->createFile('app/Bar.php', '<?php class Bar {}');
        $this->createFile('app/style.css', 'body {}');
        $this->createFile('app/script.js', 'var x = 1;');

        $files = $this->analyzer->exposeGetPhpFiles($this->tempPath());

        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertStringEndsWith('.php', $file);
        }
    }

    public function testGetPhpFilesExcludesVendorDirectory(): void
    {
        $this->createFile('app/Foo.php', '<?php class Foo {}');
        $this->createFile('vendor/Lib.php', '<?php class Lib {}');

        $files = $this->analyzer->exposeGetPhpFiles($this->tempPath());

        $this->assertCount(1, $files);
        $this->assertStringContainsString('Foo.php', $files[0]);
    }

    public function testGetPhpFilesExcludesNodeModules(): void
    {
        $this->createFile('app/Foo.php', '<?php class Foo {}');
        $this->createFile('node_modules/dep.php', '<?php class Dep {}');

        $files = $this->analyzer->exposeGetPhpFiles($this->tempPath());

        $this->assertCount(1, $files);
    }

    public function testGetPhpFilesReturnsEmptyForNonExistentPath(): void
    {
        $files = $this->analyzer->exposeGetPhpFiles('/nonexistent/path/xyz123');
        $this->assertEmpty($files);
    }

    public function testReadFileReturnsContent(): void
    {
        $this->createFile('test.php', '<?php echo "hello";');
        $content = $this->analyzer->exposeReadFile($this->tempPath('test.php'));
        $this->assertSame('<?php echo "hello";', $content);
    }

    public function testReadFileReturnsEmptyStringForMissingFile(): void
    {
        $content = $this->analyzer->exposeReadFile('/nonexistent/file.php');
        $this->assertSame('', $content);
    }

    public function testExtractClassNameDetectsClass(): void
    {
        $content = '<?php namespace App; class FooBar {}';
        $this->assertSame('FooBar', $this->analyzer->exposeExtractClassName($content));
    }

    public function testExtractClassNameDetectsInterface(): void
    {
        $content = '<?php interface FooInterface {}';
        $this->assertSame('FooInterface', $this->analyzer->exposeExtractClassName($content));
    }

    public function testExtractClassNameDetectsTrait(): void
    {
        $content = '<?php trait FooTrait {}';
        $this->assertSame('FooTrait', $this->analyzer->exposeExtractClassName($content));
    }

    public function testExtractClassNameDetectsEnum(): void
    {
        $content = '<?php enum Status: string {}';
        $this->assertSame('Status', $this->analyzer->exposeExtractClassName($content));
    }

    public function testExtractClassNameReturnsNullWhenNoClass(): void
    {
        $content = '<?php $x = 1;';
        $this->assertNull($this->analyzer->exposeExtractClassName($content));
    }

    public function testExtractNamespaceReturnsNamespace(): void
    {
        $content = '<?php namespace App\Http\Controllers; class Foo {}';
        $this->assertSame('App\Http\Controllers', $this->analyzer->exposeExtractNamespace($content));
    }

    public function testExtractNamespaceReturnsNullWhenMissing(): void
    {
        $content = '<?php class Foo {}';
        $this->assertNull($this->analyzer->exposeExtractNamespace($content));
    }

    public function testCountLinesSingleLine(): void
    {
        $this->assertSame(1, $this->analyzer->exposeCountLines('single line'));
    }

    public function testCountLinesMultipleLines(): void
    {
        $content = "line1\nline2\nline3";
        $this->assertSame(3, $this->analyzer->exposeCountLines($content));
    }

    public function testScoreToRiskLow(): void
    {
        $this->assertSame('LOW', $this->analyzer->exposeScoreToRisk(85.0));
        $this->assertSame('LOW', $this->analyzer->exposeScoreToRisk(80.0));
    }

    public function testScoreToRiskMedium(): void
    {
        $this->assertSame('MEDIUM', $this->analyzer->exposeScoreToRisk(65.0));
        $this->assertSame('MEDIUM', $this->analyzer->exposeScoreToRisk(60.0));
    }

    public function testScoreToRiskHigh(): void
    {
        $this->assertSame('HIGH', $this->analyzer->exposeScoreToRisk(45.0));
        $this->assertSame('HIGH', $this->analyzer->exposeScoreToRisk(40.0));
    }

    public function testScoreToRiskCritical(): void
    {
        $this->assertSame('CRITICAL', $this->analyzer->exposeScoreToRisk(39.9));
        $this->assertSame('CRITICAL', $this->analyzer->exposeScoreToRisk(0.0));
    }

    public function testBuildResultHasRequiredKeys(): void
    {
        $result = $this->analyzer->exposeBuildResult(75.0, ['key' => 'val'], 'summary text');

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('risk', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function testBuildResultScore(): void
    {
        $result = $this->analyzer->exposeBuildResult(75.123, [], '');
        $this->assertSame(75.12, $result['score']);
    }

    public function testAddIssueAccumulatesIssues(): void
    {
        $this->analyzer->exposeAddIssue('CRITICAL', 'file.php', 'message one');
        $this->analyzer->exposeAddIssue('HIGH', 'other.php', 'message two', 42);

        $issues = $this->analyzer->getIssues();
        $this->assertCount(2, $issues);
        $this->assertSame('CRITICAL', $issues[0]['severity']);
        $this->assertSame('file.php', $issues[0]['file']);
        $this->assertSame(42, $issues[1]['line']);
    }
}
