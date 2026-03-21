<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\CouplingCohesionAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class CouplingCohesionAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private CouplingCohesionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new CouplingCohesionAnalyzer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testDetectsGodClassByMethodCount(): void
    {
        $methods = '';
        for ($i = 1; $i <= 21; $i++) {
            $methods .= "    public function method{$i}(): void {}\n";
        }
        $this->createFile('app/BigClass.php', "<?php\nclass BigClass {\n{$methods}}");

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['god_classes']);
    }

    public function testDoesNotFlagSmallClass(): void
    {
        $methods = '';
        for ($i = 1; $i <= 5; $i++) {
            $methods .= "    public function method{$i}(): void {}\n";
        }
        $this->createFile('app/SmallClass.php', "<?php\nclass SmallClass {\n{$methods}}");

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(0, $result['metrics']['god_classes']);
    }

    public function testDetectsHighCoupling(): void
    {
        $uses = '';
        for ($i = 1; $i <= 12; $i++) {
            $uses .= "use App\\Models\\Model{$i};\n";
        }
        $this->createFile('app/HighlyCoupled.php', "<?php\nnamespace App;\n{$uses}\nclass HighlyCoupled {\n    public function go(): void {}\n}");

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['high_coupling_classes']);
    }

    public function testResultHasRequiredKeys(): void
    {
        $this->createFile('app/Simple.php', '<?php class Simple { public function go(): void {} }');
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('total_classes', $result['metrics']);
        $this->assertArrayHasKey('god_classes', $result['metrics']);
        $this->assertArrayHasKey('high_coupling_classes', $result['metrics']);
        $this->assertArrayHasKey('long_methods', $result['metrics']);
    }

    public function testEmptyProjectReturnsValidResult(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertSame(0, $result['metrics']['total_classes']);
    }

    public function testScoreIsBetweenZeroAndHundred(): void
    {
        $this->createFile('app/A.php', '<?php class A { public function go(): void {} }');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testAnalyzesFixtureProject(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/LaravelProject';
        $result = $this->analyzer->analyze($fixturePath);

        // OrderService has 12+ use statements = high coupling
        // GodClass has 21+ methods
        $this->assertGreaterThan(0, $result['metrics']['total_classes']);
        $this->assertGreaterThan(0, $result['metrics']['god_classes']);
    }

    public function testLongMethodDetected(): void
    {
        $bodyLines = str_repeat("        \$x = 1;\n", 55);
        $content = "<?php\nclass LongMethodClass {\n    public function longMethod(): void {\n{$bodyLines}    }\n}";
        $this->createFile('app/LongMethodClass.php', $content);

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['long_methods']);
    }
}
