<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analyzes Technical Debt risk in a Laravel project.
 *
 * Detects: TODOs/FIXMEs, commented-out code, outdated dependencies,
 * pending migrations, deprecated functions, and Laravel anti-patterns.
 */
class TechnicalDebtAnalyzer extends BaseAnalyzer
{
    private const DEBT_INDICATORS = [
        'TODO'     => ['severity' => 'LOW',    'label' => 'Pending TODO'],
        'FIXME'    => ['severity' => 'HIGH',   'label' => 'Critical FIXME'],
        'HACK'     => ['severity' => 'HIGH',   'label' => 'Temporary hack'],
        'XXX'      => ['severity' => 'MEDIUM', 'label' => 'Problematic code'],
        'TEMP'     => ['severity' => 'LOW',    'label' => 'Temporary code'],
        'WORKAROUND' => ['severity' => 'MEDIUM', 'label' => 'Workaround'],
        'DEPRECATED' => ['severity' => 'HIGH', 'label' => 'Deprecated code'],
        'NOSONAR'  => ['severity' => 'MEDIUM', 'label' => 'Analysis suppression'],
    ];

    private const LARAVEL_ANTIPATTERNS = [
        '/DB::statement\s*\(\s*["\'](?!CREATE|DROP|ALTER|TRUNCATE)/i' => [
            'msg' => "Using DB::statement() for queries. Consider using the Query Builder or Eloquent.",
            'severity' => 'MEDIUM',
        ],
        '/\$_(?:GET|POST|REQUEST|SERVER|COOKIE)\s*\[/' => [
            'msg' => "Direct access to PHP superglobals (\$_GET, \$_POST). Use Laravel's Request instead.",
            'severity' => 'HIGH',
        ],
        '/(?:echo|print)\s+(?!\$this)/' => [
            'msg' => "Using echo/print instead of return or Blade. Can cause testing issues.",
            'severity' => 'LOW',
        ],
        '/new\s+[A-Z]\w+\s*\(/' => [
            'msg' => "Direct class instantiation (new ClassName). Consider dependency injection.",
            'severity' => 'LOW',
        ],
        '/sleep\s*\(\s*\d+\s*\)/' => [
            'msg' => "Using sleep() in production code. Use queued Jobs or scheduled tasks instead.",
            'severity' => 'HIGH',
        ],
        '/->where\(["\'][^"\']+["\'],\s*["\']["\']/' => [
            'msg' => "WHERE with empty value detected. May return incorrect results.",
            'severity' => 'MEDIUM',
        ],
        '/Route::(?:get|post|put|delete|patch)\s*\([^,]+,\s*function\s*\(/' => [
            'msg' => "Route Closure instead of Controller. Hurts testability and reusability.",
            'severity' => 'MEDIUM',
        ],
    ];

    public function analyze(string $projectPath): array
    {
        $this->projectPath = $projectPath;
        $this->issues = [];
        $this->recommendations = [];

        $files = $this->getPhpFiles($projectPath);

        $debtIndicators = [];
        $antipatterns   = [];
        $commentedCode  = 0;
        $totalDebtScore = 0;

        foreach ($files as $file) {
            $content      = $this->readFile($file);
            $relativePath = str_replace($projectPath . '/', '', $file);

            // Scan debt indicators (TODO, FIXME, etc.)
            foreach (self::DEBT_INDICATORS as $keyword => $info) {
                $count = preg_match_all("/\/\/\s*{$keyword}|\/\*.*?{$keyword}.*?\*\//is", $content, $matches, PREG_OFFSET_CAPTURE);
                if ($count > 0) {
                    $debtIndicators[$keyword] = ($debtIndicators[$keyword] ?? 0) + $count;
                    $severity = $info['severity'];
                    $totalDebtScore += match($severity) { 'CRITICAL' => 10, 'HIGH' => 5, 'MEDIUM' => 3, default => 1 };

                    if ($count > 2) {
                        $this->addIssue($severity, $relativePath, "{$count}x {$info['label']} found in this file.");
                    }
                }
            }

            // Scan Laravel anti-patterns
            foreach (self::LARAVEL_ANTIPATTERNS as $pattern => $info) {
                if (preg_match_all($pattern, $content, $m)) {
                    $count = count($m[0]);
                    $antipatterns[] = ['file' => $relativePath, 'issue' => $info['msg'], 'count' => $count];
                    $this->addIssue($info['severity'], $relativePath, $info['msg']);
                    $totalDebtScore += match($info['severity']) { 'HIGH' => 4, 'MEDIUM' => 2, default => 1 };
                }
            }

            // Detect large blocks of commented code
            $commentedBlocks = preg_match_all('/(?:\/\*[\s\S]{200,}?\*\/|(?:\/\/[^\n]{10,}\n){5,})/m', $content, $m);
            $commentedCode += $commentedBlocks;
        }

        // Check composer.json for outdated dependencies indicators
        $composerIssues = $this->analyzeComposer($projectPath);

        // Check for missing .env.example entries
        $envIssues = $this->analyzeEnvironmentConfig($projectPath);

        // Check migrations
        $migrationIssues = $this->analyzeMigrations($projectPath);

        // Calculate final score
        $debtPoints   = $totalDebtScore + ($commentedCode * 2);
        $score        = max(0, 100 - $debtPoints);

        $this->generateDebtRecommendations($debtIndicators, $commentedCode, $composerIssues);

        return $this->buildResult($score, [
            'debt_indicators'     => $debtIndicators,
            'total_debt_markers'  => array_sum($debtIndicators),
            'anti_patterns_found' => count($antipatterns),
            'commented_code_blocks' => $commentedCode,
            'composer_issues'     => $composerIssues,
            'env_issues'          => $envIssues,
            'migration_issues'    => $migrationIssues,
            'debt_score_points'   => $debtPoints,
            'worst_antipatterns'  => array_slice($antipatterns, 0, 5),
        ], "Technical debt detected: {$debtPoints} debt points. " .
           "TODO/FIXME: " . array_sum($debtIndicators) . ". Anti-patterns: " . count($antipatterns) . ".");
    }

    private function analyzeComposer(string $projectPath): array
    {
        $issues = [];
        $composerFile = $projectPath . '/composer.json';
        if (!file_exists($composerFile)) return $issues;

        $composer = json_decode($this->readFile($composerFile), true);
        if (!$composer) return $issues;

        // Check for wildcard versions (*)
        $allDeps = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? []
        );

        foreach ($allDeps as $package => $version) {
            if ($version === '*' || $version === '@dev') {
                $issues[] = "Dependency without fixed version: {$package} ({$version})";
                $this->addIssue('HIGH', 'composer.json', "Wildcard version in '{$package}': '{$version}'. Pin the version for reproducible builds.");
            }
        }

        // Check for Laravel version constraint
        if (isset($composer['require']['laravel/framework'])) {
            $laravelVersion = $composer['require']['laravel/framework'];
            if (str_starts_with($laravelVersion, '^8') || str_starts_with($laravelVersion, '^7')) {
                $issues[] = "Outdated Laravel: {$laravelVersion}. Consider upgrading to Laravel 11+.";
                $this->addIssue('HIGH', 'composer.json', "Laravel {$laravelVersion} is an old version. Laravel 11+ includes important security improvements.");
            }
        }

        // Check for lock file
        if (!file_exists($projectPath . '/composer.lock')) {
            $issues[] = "composer.lock not found. Project has no locked dependencies.";
            $this->addIssue('CRITICAL', 'composer.lock', "Missing composer.lock. This prevents reproducible builds and is a security risk.");
        }

        return $issues;
    }

    private function analyzeEnvironmentConfig(string $projectPath): array
    {
        $issues = [];

        if (!file_exists($projectPath . '/.env.example')) {
            $issues[] = ".env.example not found — makes onboarding new developers harder.";
            $this->addIssue('MEDIUM', '.env.example', ".env.example does not exist. Create one with all required variables (without secret values).");
        }

        if (file_exists($projectPath . '/.env')) {
            $envContent = $this->readFile($projectPath . '/.env');
            if (str_contains($envContent, 'APP_ENV=production') && str_contains($envContent, 'APP_DEBUG=true')) {
                $issues[] = "APP_DEBUG=true in production environment. Critical security risk!";
                $this->addIssue('CRITICAL', '.env', "APP_DEBUG=true with APP_ENV=production exposes stack traces and environment variables. Disable it immediately!");
            }

            if (str_contains($envContent, 'APP_KEY=') && !preg_match('/APP_KEY=base64:.{40,}/', $envContent)) {
                $issues[] = "APP_KEY is not configured correctly.";
                $this->addIssue('CRITICAL', '.env', "APP_KEY is missing or has an incorrect format. Run 'php artisan key:generate'.");
            }
        }

        return $issues;
    }

    private function analyzeMigrations(string $projectPath): array
    {
        $issues = [];
        $migrationsPath = $projectPath . '/database/migrations';

        if (!is_dir($migrationsPath)) return $issues;

        $migrations = $this->getPhpFiles($migrationsPath);
        foreach ($migrations as $migration) {
            $content = $this->readFile($migration);
            if (!str_contains($content, 'public function down()') && !str_contains($content, 'public function down():')) {
                $filename = basename($migration);
                $issues[] = "Migration without down() method: {$filename}";
                $this->addIssue('MEDIUM', "database/migrations/{$filename}",
                    "Migration missing a down() method. This prevents rolling back changes.");
            }
        }

        return $issues;
    }

    private function generateDebtRecommendations(array $indicators, int $commentedCode, array $composerIssues): void
    {
        $totalTodos  = ($indicators['TODO'] ?? 0) + ($indicators['FIXME'] ?? 0);

        if ($totalTodos > 10) {
            $this->addRecommendation("There are {$totalTodos} pending TODOs/FIXMEs. Create tickets in your issue tracker (Jira, GitHub Issues) and remove the comments from code.");
        }

        if ($commentedCode > 5) {
            $this->addRecommendation("Remove commented-out code. If you need it, it's in Git history. Commented code creates confusion and ages poorly.");
        }

        if (!empty($composerIssues)) {
            $this->addRecommendation("Run 'composer outdated' to identify stale packages and update them regularly.");
        }

        $this->addRecommendation("Set up a static analysis tool: 'composer require nunomaduro/larastan --dev' (PHPStan for Laravel).");
        $this->addRecommendation("Use PHP CS Fixer or Pint (included in Laravel 9+) for consistent code style: 'php artisan pint'.");
        $this->addRecommendation("Implement code reviews before each merge to catch technical debt early.");
    }
}
