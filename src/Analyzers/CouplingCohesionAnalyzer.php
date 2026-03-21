<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analiza el acoplamiento (Coupling) y la cohesión (Cohesion) del código.
 *
 * - Acoplamiento: cuánto depende una clase de otras (bajo es mejor)
 * - Cohesión: cuánto están relacionadas las responsabilidades de una clase (alta es mejor)
 * - Detecta violaciones a Single Responsibility, God Classes, clases demasiado acopladas
 */
class CouplingCohesionAnalyzer extends BaseAnalyzer
{
    private const GOD_CLASS_METHOD_THRESHOLD    = 20;
    private const GOD_CLASS_LINE_THRESHOLD      = 500;
    private const HIGH_COUPLING_THRESHOLD       = 10;
    private const LONG_METHOD_LINE_THRESHOLD    = 50;
    private const LOW_COHESION_METHOD_THRESHOLD = 3;

    public function analyze(string $projectPath): array
    {
        $this->projectPath = $projectPath;
        $this->issues = [];
        $this->recommendations = [];

        $appPath = $projectPath . '/app';
        if (!is_dir($appPath)) {
            $appPath = $projectPath;
        }

        $files = $this->getPhpFiles($appPath);

        $totalClasses    = 0;
        $godClasses      = 0;
        $highCouplingCount = 0;
        $totalCoupling   = 0;
        $totalCohesion   = 0;
        $longMethods     = 0;
        $totalMethods    = 0;
        $classDetails    = [];

        foreach ($files as $file) {
            $content = $this->readFile($file);
            $className = $this->extractClassName($content);
            if (!$className) continue;

            $totalClasses++;
            $relativePath = str_replace($projectPath . '/', '', $file);

            // Coupling: count use/import statements + constructor injections
            $coupling = $this->calculateCoupling($content);
            $totalCoupling += $coupling;

            if ($coupling > self::HIGH_COUPLING_THRESHOLD) {
                $highCouplingCount++;
                $this->addIssue(
                    'HIGH',
                    $relativePath,
                    "Clase '{$className}' tiene alto acoplamiento ({$coupling} dependencias). Considera usar interfaces o el patrón Facade."
                );
            }

            // Cohesion: analyze method count vs responsibilities
            $methodCount = $this->countMethods($content);
            $totalMethods += $methodCount;
            $cohesion = $this->estimateCohesion($content, $methodCount);
            $totalCohesion += $cohesion;

            // God Class detection
            $lineCount = $this->countLines($content);
            $isGodClass = ($methodCount > self::GOD_CLASS_METHOD_THRESHOLD || $lineCount > self::GOD_CLASS_LINE_THRESHOLD);
            if ($isGodClass) {
                $godClasses++;
                $this->addIssue(
                    'CRITICAL',
                    $relativePath,
                    "God Class detectada: '{$className}' tiene {$methodCount} métodos y {$lineCount} líneas. " .
                    "Considera dividirla en clases más pequeñas con responsabilidad única (SRP)."
                );
            }

            // Long methods detection
            $longMethodsList = $this->findLongMethods($content, $relativePath, $className);
            $longMethods += count($longMethodsList);

            $classDetails[] = [
                'class'    => $className,
                'file'     => $relativePath,
                'coupling' => $coupling,
                'methods'  => $methodCount,
                'lines'    => $lineCount,
                'cohesion' => $cohesion,
            ];
        }

        // Calculate scores
        $avgCoupling  = $totalClasses > 0 ? $totalCoupling / $totalClasses : 0;
        $avgCohesion  = $totalClasses > 0 ? $totalCohesion / $totalClasses : 100;
        $godClassRate = $totalClasses > 0 ? ($godClasses / $totalClasses) * 100 : 0;

        // Coupling score: lower is better (penalize high coupling)
        $couplingScore = max(0, 100 - ($avgCoupling * 5));

        // Cohesion score: higher is better
        $cohesionScore = $avgCohesion;

        // God class penalty
        $godClassPenalty = $godClassRate * 2;

        $finalScore = max(0, (($couplingScore * 0.4) + ($cohesionScore * 0.4)) - $godClassPenalty - ($longMethods * 0.5));
        $finalScore = min(100, $finalScore);

        // Recommendations
        $this->generateCouplingRecommendations($avgCoupling, $godClasses, $longMethods, $totalClasses);

        // Sort by coupling to show worst offenders
        usort($classDetails, fn($a, $b) => $b['coupling'] <=> $a['coupling']);

        return $this->buildResult($finalScore, [
            'total_classes'        => $totalClasses,
            'avg_coupling'         => round($avgCoupling, 2),
            'avg_cohesion_score'   => round($avgCohesion, 2),
            'god_classes'          => $godClasses,
            'god_class_rate'       => round($godClassRate, 2) . '%',
            'high_coupling_classes' => $highCouplingCount,
            'long_methods'         => $longMethods,
            'total_methods'        => $totalMethods,
            'worst_offenders'      => array_slice($classDetails, 0, 5),
        ], sprintf("Acoplamiento promedio: %.1f deps. God classes: %d. Métodos largos: %d.", $avgCoupling, $godClasses, $longMethods));
    }

    private function calculateCoupling(string $content): int
    {
        $coupling = 0;

        // Count use statements
        $useCount = preg_match_all('/^use\s+[\w\\\\]+(?:\s+as\s+\w+)?\s*;/m', $content, $matches);
        $coupling += $useCount;

        // Count constructor injected dependencies
        if (preg_match('/public\s+function\s+__construct\s*\(([^)]+)\)/s', $content, $ctorMatch)) {
            $params = preg_match_all('/\b[A-Z]\w+\s+\$\w+/', $ctorMatch[1], $paramMatches);
            $coupling += $params;
        }

        // Count static calls (tight coupling indicator)
        $staticCalls = preg_match_all('/[A-Z]\w+::[a-z]\w+\s*\(/', $content, $staticMatches);
        $coupling += (int)($staticCalls * 0.5);

        return $coupling;
    }

    private function countMethods(string $content): int
    {
        return preg_match_all('/(?:public|protected|private)\s+(?:static\s+)?function\s+\w+\s*\(/', $content, $matches);
    }

    private function estimateCohesion(string $content, int $methodCount): float
    {
        if ($methodCount === 0) return 100.0;

        // Low cohesion signals: few methods + many external calls
        $externalCalls = preg_match_all('/\$(?:this->)?[a-z]\w+->\w+\s*\(/', $content, $m);
        $selfUsage     = preg_match_all('/\$this->\w+/', $content, $m2);

        if ($selfUsage === 0 && $methodCount < self::LOW_COHESION_METHOD_THRESHOLD) {
            return 40.0; // Static helper class - low cohesion
        }

        $ratio = $selfUsage > 0 ? min(100, ($selfUsage / max(1, $methodCount)) * 10) : 50;
        return round(max(20, min(100, $ratio)), 2);
    }

    private function findLongMethods(string $content, string $file, string $className): array
    {
        $long = [];
        $pattern = '/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\([^{]*\)\s*(?::\s*\w+\s*)?\{/';

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $long;
        }

        $lines = explode(PHP_EOL, $content);
        $totalLines = count($lines);

        foreach ($matches[0] as $idx => $match) {
            $methodName = $matches[1][$idx][0];
            $startPos   = $match[1];
            $startLine  = substr_count(substr($content, 0, $startPos), PHP_EOL) + 1;

            // Estimate method end (simplified brace counting)
            $endLine = min($startLine + 200, $totalLines);
            $depth   = 0;
            $inMethod = false;
            for ($i = $startLine - 1; $i < $totalLines; $i++) {
                $line = $lines[$i];
                if (str_contains($line, '{')) { $depth += substr_count($line, '{'); $inMethod = true; }
                if (str_contains($line, '}')) {
                    $depth -= substr_count($line, '}');
                    if ($inMethod && $depth <= 0) { $endLine = $i + 1; break; }
                }
            }

            $methodLines = $endLine - $startLine;
            if ($methodLines > self::LONG_METHOD_LINE_THRESHOLD) {
                $long[] = $methodName;
                $this->addIssue(
                    'MEDIUM',
                    $file,
                    "Método largo: '{$className}::{$methodName}' tiene ~{$methodLines} líneas (max recomendado: " . self::LONG_METHOD_LINE_THRESHOLD . "). Considera extraerlo en métodos más pequeños.",
                    $startLine
                );
            }
        }

        return $long;
    }

    private function generateCouplingRecommendations(float $avgCoupling, int $godClasses, int $longMethods, int $total): void
    {
        if ($avgCoupling > 8) {
            $this->addRecommendation("Reduce el acoplamiento usando interfaces y el patrón de Repositorio para acceso a datos.");
            $this->addRecommendation("Usa el Service Container de Laravel para inyección de dependencias en lugar de instanciar clases directamente.");
        }

        if ($godClasses > 0) {
            $this->addRecommendation("Divide las God Classes aplicando el Principio de Responsabilidad Única (SRP). Extrae servicios específicos.");
            $this->addRecommendation("Considera usar el patrón Action/UseCase para encapsular lógica de negocio compleja.");
        }

        if ($longMethods > 5) {
            $this->addRecommendation("Refactoriza métodos largos: cada método debe hacer una sola cosa y caber en una pantalla (< 50 líneas).");
        }

        $this->addRecommendation("Aplica el principio SOLID en todas las clases. Usa 'php artisan make:interface' para definir contratos.");
        $this->addRecommendation("Revisa el uso excesivo de Facades: pueden ocultar dependencias reales. Inyéctalas explícitamente.");
    }
}
