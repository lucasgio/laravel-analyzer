<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\TechnicalDebtAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class TechnicalDebtAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private TechnicalDebtAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new TechnicalDebtAnalyzer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testDetectsTodoComments(): void
    {
        $this->createFile('app/Service.php', '<?php
class Service {
    public function run(): void {
        // TODO: implement this
        // TODO: fix later
        // TODO: needs refactoring
        // TODO: remove this hack
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(0, $result['metrics']['debt_indicators']['TODO'] ?? 0);
    }

    public function testDetectsFixmeComments(): void
    {
        $this->createFile('app/Broken.php', '<?php
class Broken {
    public function broken(): void {
        // FIXME: this is broken
        // FIXME: do not use in production
        // FIXME: causes data corruption
        // FIXME: security vulnerability here
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(0, $result['metrics']['debt_indicators']['FIXME'] ?? 0);
    }

    public function testDetectsHackComments(): void
    {
        $this->createFile('app/Hacky.php', '<?php
class Hacky {
    // HACK: temporary workaround for bug
    // HACK: should be removed
    // HACK: needed for now
    // HACK: do not ship
    public function hacky(): void {}
}');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(0, $result['metrics']['debt_indicators']['HACK'] ?? 0);
    }

    public function testDetectsSuperglobalsAntipattern(): void
    {
        $this->createFile('app/LegacyCode.php', '<?php
class LegacyCode {
    public function old(): void {
        $name = $_GET["name"];
        $post = $_POST["data"];
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(0, $result['metrics']['anti_patterns_found']);
    }

    public function testDetectsSleepInProductionCode(): void
    {
        $this->createFile('app/BadWorker.php', '<?php
class BadWorker {
    public function doWork(): void {
        sleep(5);
    }
}');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(0, $result['metrics']['anti_patterns_found']);
    }

    public function testDetectsRouteClosures(): void
    {
        $this->createFile('routes/web.php', '<?php
Route::get("/admin", function () {
    return view("admin");
});');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertGreaterThan(0, $result['metrics']['anti_patterns_found']);
    }

    public function testDetectsWildcardComposerDependency(): void
    {
        $this->createFile('composer.json', json_encode([
            'require' => ['some/package' => '*']
        ]));

        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertNotEmpty($result['metrics']['composer_issues']);
    }

    public function testDetectsOutdatedLaravelVersion(): void
    {
        $this->createFile('composer.json', json_encode([
            'require' => ['laravel/framework' => '^8.0']
        ]));

        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertNotEmpty($result['metrics']['composer_issues']);
    }

    public function testDetectsMissingComposerLock(): void
    {
        $this->createFile('composer.json', json_encode([
            'require' => ['php' => '>=8.1']
        ]));
        // No composer.lock

        $result = $this->analyzer->analyze($this->tempPath());

        $hasLockIssue = false;
        foreach ($result['issues'] as $issue) {
            if (str_contains($issue['message'], 'composer.lock')) {
                $hasLockIssue = true;
            }
        }
        $this->assertTrue($hasLockIssue);
    }

    public function testDetectsMissingEnvExample(): void
    {
        // No .env.example file
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertNotEmpty($result['metrics']['env_issues']);
    }

    public function testDetectsAppDebugTrueInProduction(): void
    {
        $this->createFile('.env', "APP_ENV=production\nAPP_DEBUG=true\nAPP_KEY=base64:abc=");
        $result = $this->analyzer->analyze($this->tempPath());

        $hasCritical = false;
        foreach ($result['issues'] as $issue) {
            if ($issue['severity'] === 'CRITICAL' && str_contains($issue['message'], 'APP_DEBUG')) {
                $hasCritical = true;
            }
        }
        $this->assertTrue($hasCritical);
    }

    public function testDetectsMigrationMissingDown(): void
    {
        $this->createFile('database/migrations/2024_01_test.php', '<?php
use Illuminate\Database\Migrations\Migration;
class TestMigration extends Migration {
    public function up(): void {}
    // Missing down()
}');

        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertNotEmpty($result['metrics']['migration_issues']);
    }

    public function testMigrationWithDownHasNoIssue(): void
    {
        $this->createFile('database/migrations/2024_01_clean.php', '<?php
use Illuminate\Database\Migrations\Migration;
class CleanMigration extends Migration {
    public function up(): void {}
    public function down(): void {}
}');
        $result = $this->analyzer->analyze($this->tempPath());
        $this->assertEmpty($result['metrics']['migration_issues']);
    }

    public function testResultHasRequiredKeys(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('debt_indicators', $result['metrics']);
        $this->assertArrayHasKey('anti_patterns_found', $result['metrics']);
        $this->assertArrayHasKey('composer_issues', $result['metrics']);
        $this->assertArrayHasKey('env_issues', $result['metrics']);
        $this->assertArrayHasKey('migration_issues', $result['metrics']);
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

        // OrderService.php has TODO/FIXME/HACK
        $totalMarkers = $result['metrics']['total_debt_markers'];
        $this->assertGreaterThan(0, $totalMarkers);

        // composer.json has wildcard dep
        $this->assertNotEmpty($result['metrics']['composer_issues']);

        // migration missing down()
        $this->assertNotEmpty($result['metrics']['migration_issues']);
    }
}
