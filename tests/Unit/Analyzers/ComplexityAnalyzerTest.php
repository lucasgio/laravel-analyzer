<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\ComplexityAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class ComplexityAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private ComplexityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new ComplexityAnalyzer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testSimpleMethodHasLowComplexity(): void
    {
        $this->createFile('app/SimpleClass.php', '<?php
class SimpleClass {
    public function greet(): string {
        return "hello";
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(0, $result['metrics']['high_complexity_methods']);
    }

    public function testMethodWithManyBranchesHasHighComplexity(): void
    {
        $this->createFile('app/ComplexClass.php', '<?php
class ComplexClass {
    public function superComplex($a, $b, $c, $d, $e, $f): int {
        if ($a > 0) {
            if ($b > 0) {
                if ($c > 0) {
                    foreach ([1,2,3] as $i) {
                        if ($i > 0) {
                            while ($i > 0) {
                                if ($d) {
                                    try {
                                        if ($e) {
                                            $i = $f ? 0 : $i - 1;
                                        }
                                    } catch (\Exception $ex) {
                                        if ($ex) return 0;
                                    }
                                } else {
                                    $i--;
                                }
                            }
                        }
                    }
                } elseif ($c < 0) {
                    return -1;
                }
            } elseif ($b < 0) {
                return -2;
            }
        } elseif ($a < 0) {
            return -3;
        }
        return $a && $b ? 1 : 0;
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['high_complexity_methods']);
    }

    public function testDeepNestingDetected(): void
    {
        $this->createFile('app/DeeplyNested.php', '<?php
class DeeplyNested {
    public function deep(): void {
        if (true) {
            foreach ([1] as $a) {
                while ($a > 0) {
                    try {
                        if ($a === 1) {
                            $a--;
                        }
                    } catch (\Exception $e) {}
                }
            }
        }
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['deep_nesting_classes']);
    }

    public function testLongClassDetected(): void
    {
        // Create a class with >300 lines (80 methods × 4 newlines each = 320 + 3 header = 323 newlines → 324 lines)
        $methods = '';
        for ($i = 0; $i < 80; $i++) {
            $methods .= "    public function method{$i}(): string {\n";
            $methods .= "        // padding\n        return 'result';\n    }\n\n";
        }

        $content = "<?php\nclass BigClass {\n{$methods}\n}";
        $this->createFile('app/BigClass.php', $content);

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['long_classes']);
    }

    public function testDuplicationDetected(): void
    {
        // Block must be >= 100 chars after whitespace collapse
        $duplicateBlock = "\n" .
            "    \$result = [];\n" .
            "    \$result['id'] = \$id;\n" .
            "    \$result['name'] = \$name;\n" .
            "    \$result['email'] = \$email;\n" .
            "    \$result['status'] = 'active';\n" .
            "    \$result['created_at'] = date('Y-m-d H:i:s');";

        $this->createFile('app/FileA.php', "<?php\nclass FileA {\n    public function build(\$id, \$name, \$email) {{$duplicateBlock}\n    }\n}");
        $this->createFile('app/FileB.php', "<?php\nclass FileB {\n    public function build(\$id, \$name, \$email) {{$duplicateBlock}\n    }\n}");

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['duplicate_blocks']);
    }

    public function testResultHasRequiredKeys(): void
    {
        $this->createFile('app/Simple.php', '<?php class Simple { public function go(): void {} }');
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('total_methods', $result['metrics']);
        $this->assertArrayHasKey('avg_cyclomatic_complexity', $result['metrics']);
        $this->assertArrayHasKey('high_complexity_methods', $result['metrics']);
        $this->assertArrayHasKey('deep_nesting_classes', $result['metrics']);
        $this->assertArrayHasKey('duplicate_blocks', $result['metrics']);
    }

    public function testEmptyProjectScores100(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertSame(100.0, $result['score']);
    }

    public function testScoreIsBetweenZeroAndHundred(): void
    {
        $this->createFile('app/Foo.php', '<?php class Foo { public function bar(): int { return 1; } }');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testAnalyzesFixtureProject(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/LaravelProject';
        $result = $this->analyzer->analyze($fixturePath);

        // GodClass.php has deep nesting
        $this->assertGreaterThan(0, $result['metrics']['deep_nesting_classes']);
        $this->assertArrayHasKey('score', $result);
    }
}
