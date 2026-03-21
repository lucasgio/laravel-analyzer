<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analiza el riesgo de Deuda Técnica en el proyecto Laravel.
 *
 * Detecta: TODOs/FIXMEs, código comentado, dependencias desactualizadas,
 * migraciones sin ejecutar, deprecated functions, anti-patterns de Laravel.
 */
class TechnicalDebtAnalyzer extends BaseAnalyzer
{
    private const DEBT_INDICATORS = [
        'TODO'     => ['severity' => 'LOW',    'label' => 'TODO pendiente'],
        'FIXME'    => ['severity' => 'HIGH',   'label' => 'FIXME crítico'],
        'HACK'     => ['severity' => 'HIGH',   'label' => 'Hack temporal'],
        'XXX'      => ['severity' => 'MEDIUM', 'label' => 'Código problemático'],
        'TEMP'     => ['severity' => 'LOW',    'label' => 'Código temporal'],
        'WORKAROUND' => ['severity' => 'MEDIUM', 'label' => 'Workaround'],
        'DEPRECATED' => ['severity' => 'HIGH', 'label' => 'Código deprecado'],
        'NOSONAR'  => ['severity' => 'MEDIUM', 'label' => 'Supresión de análisis'],
    ];

    private const LARAVEL_ANTIPATTERNS = [
        '/DB::statement\s*\(\s*["\'](?!CREATE|DROP|ALTER|TRUNCATE)/i' => [
            'msg' => "Uso de DB::statement() para queries. Considera usar el Query Builder o Eloquent.",
            'severity' => 'MEDIUM',
        ],
        '/\$_(?:GET|POST|REQUEST|SERVER|COOKIE)\s*\[/' => [
            'msg' => "Acceso directo a superglobales PHP (\$_GET, \$_POST). Usa Request de Laravel.",
            'severity' => 'HIGH',
        ],
        '/(?:echo|print)\s+(?!\$this)/' => [
            'msg' => "Uso de echo/print en lugar de return o Blade. Puede causar problemas de testing.",
            'severity' => 'LOW',
        ],
        '/new\s+[A-Z]\w+\s*\(/' => [
            'msg' => "Instanciación directa de clases (new ClassName). Considera inyección de dependencias.",
            'severity' => 'LOW',
        ],
        '/sleep\s*\(\s*\d+\s*\)/' => [
            'msg' => "Uso de sleep() en código de producción. Usa Jobs en cola o scheduled tasks.",
            'severity' => 'HIGH',
        ],
        '/->where\(["\'][^"\']+["\'],\s*["\']["\']/' => [
            'msg' => "WHERE con valor vacío detectado. Podría retornar resultados incorrectos.",
            'severity' => 'MEDIUM',
        ],
        '/Route::(?:get|post|put|delete|patch)\s*\([^,]+,\s*function\s*\(/' => [
            'msg' => "Closure en Route en lugar de Controller. Dificulta el testing y la reutilización.",
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
                        $this->addIssue($severity, $relativePath, "{$count}x {$info['label']} encontrado en este archivo.");
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
        ], "Deuda técnica detectada: {$debtPoints} puntos de deuda. " .
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
                $issues[] = "Dependencia sin versión fija: {$package} ({$version})";
                $this->addIssue('HIGH', 'composer.json', "Versión comodín en '{$package}': '{$version}'. Fija la versión para builds reproducibles.");
            }
        }

        // Check for Laravel version constraint
        if (isset($composer['require']['laravel/framework'])) {
            $laravelVersion = $composer['require']['laravel/framework'];
            if (str_starts_with($laravelVersion, '^8') || str_starts_with($laravelVersion, '^7')) {
                $issues[] = "Laravel desactualizado: {$laravelVersion}. Considera actualizar a Laravel 11+.";
                $this->addIssue('HIGH', 'composer.json', "Laravel {$laravelVersion} es una versión antigua. Laravel 11+ tiene mejoras de seguridad importantes.");
            }
        }

        // Check for lock file
        if (!file_exists($projectPath . '/composer.lock')) {
            $issues[] = "composer.lock no encontrado. El proyecto no tiene dependencias bloqueadas.";
            $this->addIssue('CRITICAL', 'composer.lock', "Falta composer.lock. Esto impide builds reproducibles y es un riesgo de seguridad.");
        }

        return $issues;
    }

    private function analyzeEnvironmentConfig(string $projectPath): array
    {
        $issues = [];

        if (!file_exists($projectPath . '/.env.example')) {
            $issues[] = ".env.example no encontrado - dificulta la configuración de nuevos desarrolladores.";
            $this->addIssue('MEDIUM', '.env.example', "No existe .env.example. Crea uno con todas las variables necesarias (sin valores secretos).");
        }

        if (file_exists($projectPath . '/.env')) {
            $envContent = $this->readFile($projectPath . '/.env');
            if (str_contains($envContent, 'APP_ENV=production') && str_contains($envContent, 'APP_DEBUG=true')) {
                $issues[] = "APP_DEBUG=true en entorno de producción. ¡Riesgo de seguridad crítico!";
                $this->addIssue('CRITICAL', '.env', "APP_DEBUG=true con APP_ENV=production expone stacktraces y variables de entorno. ¡Deshabilítalo inmediatamente!");
            }

            if (str_contains($envContent, 'APP_KEY=') && !preg_match('/APP_KEY=base64:.{40,}/', $envContent)) {
                $issues[] = "APP_KEY no configurada correctamente.";
                $this->addIssue('CRITICAL', '.env', "APP_KEY no tiene el formato correcto o está vacía. Ejecuta 'php artisan key:generate'.");
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
                $issues[] = "Migración sin método down(): {$filename}";
                $this->addIssue('MEDIUM', "database/migrations/{$filename}",
                    "Migración sin método down(). Esto impide hacer rollback de cambios.");
            }
        }

        return $issues;
    }

    private function generateDebtRecommendations(array $indicators, int $commentedCode, array $composerIssues): void
    {
        $totalTodos  = ($indicators['TODO'] ?? 0) + ($indicators['FIXME'] ?? 0);

        if ($totalTodos > 10) {
            $this->addRecommendation("Hay {$totalTodos} TODOs/FIXMEs pendientes. Créales tickets en tu sistema de gestión (Jira, GitHub Issues) y elimina los comentarios del código.");
        }

        if ($commentedCode > 5) {
            $this->addRecommendation("Elimina el código comentado. Si lo necesitas, está en el historial de Git. El código comentado confunde y envejece mal.");
        }

        if (!empty($composerIssues)) {
            $this->addRecommendation("Ejecuta 'composer outdated' para identificar paquetes desactualizados y actualiza periódicamente.");
        }

        $this->addRecommendation("Configura una herramienta de análisis estático: 'composer require nunomaduro/larastan --dev' (PHPStan para Laravel).");
        $this->addRecommendation("Usa PHP CS Fixer o Pint (incluido en Laravel 9+) para mantener el estilo de código consistente: 'php artisan pint'.");
        $this->addRecommendation("Implementa revisiones de código (code reviews) antes de cada merge para detectar deuda técnica temprano.");
    }
}
