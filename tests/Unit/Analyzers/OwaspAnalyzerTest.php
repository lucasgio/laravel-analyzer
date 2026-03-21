<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\OwaspAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class OwaspAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private OwaspAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new OwaspAnalyzer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testResultHasOwaspBreakdown(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('owasp_breakdown', $result['metrics']);
        $this->assertCount(10, $result['metrics']['owasp_breakdown']);
    }

    public function testOwaspBreakdownHasRequiredKeys(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        foreach ($result['metrics']['owasp_breakdown'] as $code => $entry) {
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('score', $entry);
            $this->assertArrayHasKey('risk', $entry);
            $this->assertArrayHasKey('findings_count', $entry);
        }
    }

    public function testA01BrokenAccessControlDetected(): void
    {
        // No policies directory = lower A01 score
        $result = $this->analyzer->analyze($this->tempPath());

        $a01 = $result['metrics']['owasp_breakdown']['A01'] ?? null;
        $this->assertNotNull($a01);
        $this->assertLessThan(100, $a01['score']);
    }

    public function testA02CryptoFailureDetectedWithMd5(): void
    {
        $this->createFile('app/Hash.php', '<?php
class HashHelper {
    public function makeHash($pass) {
        return md5($pass);
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());

        $a02 = $result['metrics']['owasp_breakdown']['A02'] ?? null;
        $this->assertNotNull($a02);
        $this->assertGreaterThan(0, $a02['findings_count']);
    }

    public function testA03InjectionDetectedWithUnserialize(): void
    {
        $this->createFile('app/DataService.php', '<?php
class DataService {
    public function handle($request) {
        $data = unserialize($request);
        return $data;
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());

        $a03 = $result['metrics']['owasp_breakdown']['A03'] ?? null;
        $this->assertNotNull($a03);
        $this->assertGreaterThan(0, $a03['findings_count']);
    }

    public function testA05MisconfigWithDebugTrue(): void
    {
        $this->createFile('.env', "APP_DEBUG=true\nAPP_ENV=production");
        $this->createFile('.gitignore', '.env');

        $result = $this->analyzer->analyze($this->tempPath());

        $a05 = $result['metrics']['owasp_breakdown']['A05'] ?? null;
        $this->assertNotNull($a05);
        $this->assertLessThan(100, $a05['score']);
    }

    public function testA06VulnerableComponentsWithWildcard(): void
    {
        $this->createFile('composer.json', json_encode([
            'require' => ['some/package' => '*']
        ]));

        $result = $this->analyzer->analyze($this->tempPath());

        $a06 = $result['metrics']['owasp_breakdown']['A06'] ?? null;
        $this->assertNotNull($a06);
        $this->assertGreaterThan(0, $a06['findings_count']);
    }

    public function testScoreIsBetweenZeroAndHundred(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testResultHasRequiredKeys(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('risk', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function testAnalyzesFixtureProject(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/LaravelProject';
        $result = $this->analyzer->analyze($fixturePath);

        // Fixture has VulnerableController, VulnerableModel, wildcard dep, CORS wildcard, missing throttle
        $this->assertCount(10, $result['metrics']['owasp_breakdown']);
        $this->assertGreaterThan(0, count($result['issues']));
    }
}
