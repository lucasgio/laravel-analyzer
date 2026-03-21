<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analyzes the test coverage of a Laravel project.
 *
 * Detects: unit tests, feature tests, presence of clover.xml,
 * test-to-code ratio, coverage of models, controllers, and services.
 */
class TestCoverageAnalyzer extends BaseAnalyzer
{
    public function analyze(string $projectPath): array
    {
        $this->projectPath = $projectPath;
        $this->issues = [];
        $this->recommendations = [];

        $metrics = [];

        // 1. Check PHPUnit/Pest configuration
        $hasPhpUnit = file_exists($projectPath . '/phpunit.xml') || file_exists($projectPath . '/phpunit.xml.dist');
        $hasPest    = file_exists($projectPath . '/pest.config.php') ||
                      (file_exists($projectPath . '/composer.json') && str_contains(
                          $this->readFile($projectPath . '/composer.json'), 'pestphp/pest'
                      ));

        $metrics['test_framework'] = $hasPhpUnit ? 'PHPUnit' : ($hasPest ? 'Pest' : 'No detectado');
        $metrics['has_test_config'] = $hasPhpUnit || $hasPest;

        // 2. Count source files vs test files
        $sourceFiles = $this->countSourceFiles($projectPath . '/app');
        $testFiles   = $this->countTestFiles($projectPath . '/tests');

        $metrics['source_files'] = $sourceFiles;
        $metrics['test_files']   = $testFiles;
        $metrics['test_ratio']   = $sourceFiles > 0
            ? round(($testFiles / $sourceFiles) * 100, 1) . '%'
            : '0%';

        // 3. Categorize tests
        $unitTests    = $this->getPhpFiles($projectPath . '/tests/Unit');
        $featureTests = $this->getPhpFiles($projectPath . '/tests/Feature');

        $metrics['unit_tests']    = count($unitTests);
        $metrics['feature_tests'] = count($featureTests);

        // 4. Check for coverage XML report
        $coverageData = $this->parseCoverageReport($projectPath);
        $metrics['coverage_xml_found'] = $coverageData !== null;
        $metrics['line_coverage'] = $coverageData['line_coverage'] ?? 'Not available (run phpunit --coverage-clover)';

        // 5. Analyze test quality
        $qualityScore = $this->analyzeTestQuality($unitTests, $featureTests, $projectPath);
        $metrics['test_quality_score'] = $qualityScore;

        // 6. Check untested critical areas
        $untestedAreas = $this->findUntestedAreas($projectPath);
        $metrics['untested_areas'] = $untestedAreas;

        // 7. Check for factories and seeders
        $metrics['has_factories'] = is_dir($projectPath . '/database/factories') &&
                                    count($this->getPhpFiles($projectPath . '/database/factories')) > 0;
        $metrics['factory_count'] = is_dir($projectPath . '/database/factories')
            ? count($this->getPhpFiles($projectPath . '/database/factories'))
            : 0;

        // Score calculation
        $score = $this->calculateScore($metrics, $coverageData);

        $this->generateRecommendations($metrics);

        return $this->buildResult(
            $score,
            $metrics,
            "Tests: {$testFiles} files. Unit: {$metrics['unit_tests']} | Feature: {$metrics['feature_tests']}. " .
            "Coverage: " . ($coverageData['line_coverage'] ?? 'N/A') . "."
        );
    }

    private function countSourceFiles(string $path): int
    {
        if (!is_dir($path)) return 0;
        $files = $this->getPhpFiles($path);
        // Filter out only meaningful source files (Models, Controllers, Services, etc.)
        return count(array_filter($files, fn($f) =>
            preg_match('/\/(Models|Controllers|Services|Repositories|Actions|Jobs|Listeners|Observers|Policies|Rules|Http)\//i', $f)
        ));
    }

    private function countTestFiles(string $path): int
    {
        if (!is_dir($path)) return 0;
        return count($this->getPhpFiles($path));
    }

    private function parseCoverageReport(string $projectPath): ?array
    {
        $cloverPaths = [
            $projectPath . '/coverage.xml',
            $projectPath . '/clover.xml',
            $projectPath . '/storage/coverage/clover.xml',
            $projectPath . '/build/coverage/clover.xml',
        ];

        foreach ($cloverPaths as $path) {
            if (file_exists($path)) {
                return $this->parseCloverXml($path);
            }
        }

        return null;
    }

    private function parseCloverXml(string $path): array
    {
        try {
            $useInternalErrors = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($this->readFile($path));
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
            if (!$xml) return ['line_coverage' => 'Error parsing XML'];

            $metrics = $xml->project->metrics ?? null;
            if (!$metrics) return ['line_coverage' => 'Formato XML no reconocido'];

            $statements = (int)$metrics['statements'];
            $coveredStatements = (int)$metrics['coveredstatements'];

            $coverage = $statements > 0 ? round(($coveredStatements / $statements) * 100, 2) : 0;

            return [
                'line_coverage'    => $coverage . '%',
                'coverage_float'   => $coverage,
                'statements'       => $statements,
                'covered'          => $coveredStatements,
            ];
        } catch (\Throwable) {
            return ['line_coverage' => 'Error al parsear'];
        }
    }

    private function analyzeTestQuality(array $unitTests, array $featureTests, string $projectPath): float
    {
        $score = 50.0; // Base score

        // Check for assertions (good tests use assertions)
        $totalAssertions = 0;
        $totalTests      = 0;

        foreach (array_merge($unitTests, $featureTests) as $file) {
            $content = $this->readFile($file);
            $assertions = preg_match_all('/\$this->assert\w+\s*\(/', $content, $m);
            $testMethods = preg_match_all('/(?:public\s+function\s+test\w+|#\[Test\])/i', $content, $m2);
            $totalAssertions += $assertions;
            $totalTests += $testMethods;
        }

        $avgAssertions = $totalTests > 0 ? $totalAssertions / $totalTests : 0;

        // Penalize tests with too few assertions
        if ($avgAssertions < 1) {
            $score -= 20;
            $this->addIssue('HIGH', 'tests/', sprintf("Very low assertion average (%.1f). Tests may not be verifying anything meaningful.", $avgAssertions));
        } elseif ($avgAssertions >= 3) {
            $score += 15;
        }

        // Check for data providers / datasets
        $hasDataProviders = false;
        foreach (array_merge($unitTests, $featureTests) as $file) {
            if (str_contains($this->readFile($file), '@dataProvider') || str_contains($this->readFile($file), 'dataset(')) {
                $hasDataProviders = true;
                break;
            }
        }
        if ($hasDataProviders) $score += 10;

        // Check for mocking
        $hasMocks = false;
        foreach ($unitTests as $file) {
            $content = $this->readFile($file);
            if (str_contains($content, 'Mock') || str_contains($content, 'Mockery') || str_contains($content, 'mock(')) {
                $hasMocks = true;
                break;
            }
        }
        if ($hasMocks) $score += 10;

        return min(100, max(0, $score));
    }

    private function findUntestedAreas(string $projectPath): array
    {
        $untested = [];

        $criticalPaths = [
            'app/Http/Controllers' => 'HTTP Controllers',
            'app/Models'           => 'Eloquent Models',
            'app/Services'         => 'Business Services',
            'app/Policies'         => 'Authorization Policies',
            'app/Jobs'             => 'Queued Jobs',
            'app/Listeners'        => 'Event Listeners',
        ];

        foreach ($criticalPaths as $dir => $label) {
            $fullPath = $projectPath . '/' . $dir;
            if (!is_dir($fullPath)) continue;

            $sourceCount = count($this->getPhpFiles($fullPath));
            if ($sourceCount === 0) continue;

            // Check if test files reference these (simplified check)
            $testDir = $projectPath . '/tests';
            $testContent = '';
            foreach ($this->getPhpFiles($testDir) as $testFile) {
                $testContent .= $this->readFile($testFile);
            }

            $testedCount = 0;
            foreach ($this->getPhpFiles($fullPath) as $sourceFile) {
                $className = $this->extractClassName($this->readFile($sourceFile));
                if ($className && str_contains($testContent, $className)) {
                    $testedCount++;
                }
            }

            $coverage = $sourceCount > 0 ? round(($testedCount / $sourceCount) * 100) : 0;
            if ($coverage < 50) {
                $untested[] = [
                    'area'     => $label,
                    'path'     => $dir,
                    'files'    => $sourceCount,
                    'tested'   => $testedCount,
                    'coverage' => $coverage . '%',
                ];
                if ($coverage < 30) {
                    $this->addIssue('HIGH', $dir, "{$label}: Only {$coverage}% coverage ({$testedCount}/{$sourceCount} files referenced in tests).");
                }
            }
        }

        return $untested;
    }

    private function calculateScore(array $metrics, ?array $coverage): float
    {
        $score = 0;

        // Test framework present (10 points)
        $score += $metrics['has_test_config'] ? 10 : 0;

        // Test ratio (30 points)
        $ratio = $metrics['source_files'] > 0
            ? ($metrics['test_files'] / max(1, $metrics['source_files'])) * 100
            : 0;
        $score += min(30, $ratio * 0.5);

        // Unit vs Feature balance (20 points)
        $totalTests = $metrics['unit_tests'] + $metrics['feature_tests'];
        if ($totalTests > 0) {
            $score += min(20, $totalTests * 0.5);
        }

        // Coverage from XML (25 points)
        if ($coverage && isset($coverage['coverage_float'])) {
            $score += ($coverage['coverage_float'] / 100) * 25;
        }

        // Factories present (5 points)
        $score += $metrics['has_factories'] ? 5 : 0;

        // Quality score (10 points)
        $score += ($metrics['test_quality_score'] / 100) * 10;

        return min(100, max(0, $score));
    }

    private function generateRecommendations(array $metrics): void
    {
        if (!$metrics['has_test_config']) {
            $this->addRecommendation("Set up PHPUnit or Pest: run 'composer require pestphp/pest --dev' and initialize with 'php artisan pest:install'.");
        }

        if ($metrics['unit_tests'] === 0) {
            $this->addRecommendation("Create unit tests for services and models. Use 'php artisan make:test NameTest --unit'.");
        }

        if ($metrics['feature_tests'] === 0) {
            $this->addRecommendation("Add feature tests for your HTTP endpoints. Use 'php artisan make:test NameTest'.");
        }

        if (!$metrics['has_factories']) {
            $this->addRecommendation("Create Model Factories to generate test data: 'php artisan make:factory ModelFactory'.");
        }

        if (!$metrics['coverage_xml_found']) {
            $this->addRecommendation("Generate a coverage report: 'php artisan test --coverage-clover=coverage.xml'. Aim for at least 70%.");
        }

        $this->addRecommendation("Use RefreshDatabase in feature tests to isolate database state.");
        $this->addRecommendation("Implement CI/CD to run tests automatically on every push (GitHub Actions, GitLab CI).");
    }
}
