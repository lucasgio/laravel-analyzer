<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\TestCoverageAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class TestCoverageAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private TestCoverageAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new TestCoverageAnalyzer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testDetectsPhpUnitConfig(): void
    {
        $this->createFile('phpunit.xml', '<phpunit></phpunit>');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertTrue($result['metrics']['has_test_config']);
        $this->assertSame('PHPUnit', $result['metrics']['test_framework']);
    }

    public function testDetectsPhpUnitDistConfig(): void
    {
        $this->createFile('phpunit.xml.dist', '<phpunit></phpunit>');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertTrue($result['metrics']['has_test_config']);
    }

    public function testDetectsPestFramework(): void
    {
        $this->createFile('composer.json', json_encode([
            'require-dev' => ['pestphp/pest' => '^2.0']
        ], JSON_UNESCAPED_SLASHES));

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertTrue($result['metrics']['has_test_config']);
        $this->assertSame('Pest', $result['metrics']['test_framework']);
    }

    public function testNoTestFrameworkDetected(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertFalse($result['metrics']['has_test_config']);
    }

    public function testCountsUnitTests(): void
    {
        $this->createFile('phpunit.xml', '<phpunit></phpunit>');
        $this->createFile('tests/Unit/FooTest.php', '<?php class FooTest extends TestCase {}');
        $this->createFile('tests/Unit/BarTest.php', '<?php class BarTest extends TestCase {}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(2, $result['metrics']['unit_tests']);
    }

    public function testCountsFeatureTests(): void
    {
        $this->createFile('phpunit.xml', '<phpunit></phpunit>');
        $this->createFile('tests/Feature/UserTest.php', '<?php class UserTest extends TestCase {}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(1, $result['metrics']['feature_tests']);
    }

    public function testParsesCloverXml(): void
    {
        $this->createFile('phpunit.xml', '<phpunit></phpunit>');
        $cloverXml = '<?xml version="1.0" encoding="UTF-8"?>
<coverage>
  <project>
    <metrics statements="100" coveredstatements="80" conditionals="0" coveredconditionals="0" methods="0" coveredmethods="0"/>
  </project>
</coverage>';
        $this->createFile('clover.xml', $cloverXml);

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertTrue($result['metrics']['coverage_xml_found']);
        $this->assertSame('80%', $result['metrics']['line_coverage']);
    }

    public function testHandlesMalformedCloverXml(): void
    {
        $this->createFile('phpunit.xml', '<phpunit></phpunit>');
        $this->createFile('clover.xml', 'this is not xml !!@#$%');

        // Should not throw
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertArrayHasKey('line_coverage', $result['metrics']);
    }

    public function testDetectsFactories(): void
    {
        $this->createFile('database/factories/UserFactory.php', '<?php class UserFactory {}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertTrue($result['metrics']['has_factories']);
        $this->assertSame(1, $result['metrics']['factory_count']);
    }

    public function testNoFactoriesReturnsZero(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertFalse($result['metrics']['has_factories']);
        $this->assertSame(0, $result['metrics']['factory_count']);
    }

    public function testHighQualityTestsBoostScore(): void
    {
        $this->createFile('phpunit.xml', '<phpunit></phpunit>');
        $this->createFile('tests/Unit/HighQualityTest.php', '<?php
use PHPUnit\Framework\TestCase;
class HighQualityTest extends TestCase {
    /**
     * @dataProvider provider
     */
    public function testWithData($x): void {
        $this->assertEquals($x, $x);
        $this->assertNotNull($x);
        $this->assertIsInt($x);
    }
    public function provider(): array { return [[1],[2],[3]]; }
}');

        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(50, $result['metrics']['test_quality_score']);
    }

    public function testResultHasRequiredKeys(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('has_test_config', $result['metrics']);
        $this->assertArrayHasKey('test_framework', $result['metrics']);
        $this->assertArrayHasKey('unit_tests', $result['metrics']);
        $this->assertArrayHasKey('feature_tests', $result['metrics']);
        $this->assertArrayHasKey('has_factories', $result['metrics']);
    }

    public function testScoreIsBetweenZeroAndHundred(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testAnalyzesFixtureProject(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/LaravelProject';
        $result = $this->analyzer->analyze($fixturePath);

        // Fixture has phpunit.xml, Unit and Feature test directories, factories
        $this->assertTrue($result['metrics']['has_test_config']);
        $this->assertGreaterThan(0, $result['metrics']['unit_tests']);
        $this->assertGreaterThan(0, $result['metrics']['feature_tests']);
        $this->assertTrue($result['metrics']['has_factories']);
    }
}
