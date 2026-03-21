<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analiza la Complejidad Ciclomática y el Riesgo de Refactorización.
 *
 * - Complejidad Ciclomática: número de rutas independientes en el código
 * - Detecta: métodos con alta complejidad, anidamiento profundo, duplicación,
 *   violaciones de DRY, clases difíciles de refactorizar
 */
class ComplexityAnalyzer extends BaseAnalyzer
{
    private const CYCLOMATIC_LOW    = 5;
    private const CYCLOMATIC_MEDIUM = 10;
    private const CYCLOMATIC_HIGH   = 20;
    private const MAX_NESTING_DEPTH = 4;

    // Control flow structures that increase cyclomatic complexity
    private const COMPLEXITY_PATTERNS = [
        '/\bif\s*\(/'          => 1,
        '/\belseif\s*\(/'      => 1,
        '/\belse\b/'           => 0, // else doesn't add a path
        '/\bfor\s*\(/'         => 1,
        '/\bforeach\s*\(/'     => 1,
        '/\bwhile\s*\(/'       => 1,
        '/\bdo\s*\{/'          => 1,
        '/\bswitch\s*\(/'      => 1,
        '/\bcase\s+[^:]+:/'    => 1,
        '/\bcatch\s*\(/'       => 1,
        '/\?\?/'               => 1, // null coalescing
        '/\?(?!>|\s*:)/'       => 1, // ternary
        '/&&|\|\|/'            => 1, // logical operators
        '/\bmatch\s*\(/'       => 1, // match expression
    ];

    public function analyze(string $projectPath): array
    {
        $this->projectPath = $projectPath;
        $this->issues = [];
        $this->recommendations = [];

        $appPath = $projectPath . '/app';
        if (!is_dir($appPath)) $appPath = $projectPath;

        $files = $this->getPhpFiles($appPath);

        $allComplexities   = [];
        $highComplexity    = [];
        $deepNesting       = [];
        $longClasses       = [];
        $duplicatePatterns = [];
        $totalComplexity   = 0;
        $methodCount       = 0;

        foreach ($files as $file) {
            $content      = $this->readFile($file);
            $className    = $this->extractClassName($content);
            $relativePath = str_replace($projectPath . '/', '', $file);

            if (!$className) continue;

            // Calculate cyclomatic complexity per method
            $methods = $this->extractMethods($content);
            foreach ($methods as $method) {
                $cc = $this->calculateCyclomaticComplexity($method['body']);
                $cc = max(1, $cc); // Minimum is 1
                $totalComplexity += $cc;
                $methodCount++;

                $entry = [
                    'class'      => $className,
                    'method'     => $method['name'],
                    'file'       => $relativePath,
                    'complexity' => $cc,
                ];

                $allComplexities[] = $entry;

                if ($cc > self::CYCLOMATIC_HIGH) {
                    $highComplexity[] = $entry;
                    $this->addIssue(
                        'CRITICAL',
                        $relativePath,
                        "Complejidad Ciclomática CRÍTICA: {$className}::{$method['name']}() = {$cc}. " .
                        "Valores > " . self::CYCLOMATIC_HIGH . " indican código muy difícil de testear y mantener."
                    );
                } elseif ($cc > self::CYCLOMATIC_MEDIUM) {
                    $highComplexity[] = $entry;
                    $this->addIssue(
                        'HIGH',
                        $relativePath,
                        "Alta Complejidad Ciclomática: {$className}::{$method['name']}() = {$cc}. " .
                        "Recomendado máximo: " . self::CYCLOMATIC_MEDIUM . "."
                    );
                }
            }

            // Check nesting depth
            $maxNesting = $this->calculateMaxNesting($content);
            if ($maxNesting > self::MAX_NESTING_DEPTH) {
                $deepNesting[] = ['file' => $relativePath, 'class' => $className, 'depth' => $maxNesting];
                $this->addIssue(
                    'MEDIUM',
                    $relativePath,
                    "Anidamiento profundo detectado en '{$className}' (profundidad: {$maxNesting}). " .
                    "Considera extraer métodos o usar cláusulas de guarda (return early)."
                );
            }

            // Long class detection (refactoring risk)
            $lineCount = $this->countLines($content);
            if ($lineCount > 300) {
                $longClasses[] = ['file' => $relativePath, 'class' => $className, 'lines' => $lineCount];
            }
        }

        // Detect duplicate code blocks
        $duplicateCount = $this->detectDuplication($files, $projectPath);

        // Sort by complexity
        usort($allComplexities, fn($a, $b) => $b['complexity'] <=> $a['complexity']);

        $avgComplexity = $methodCount > 0 ? $totalComplexity / $methodCount : 0;

        // Score calculation
        $score = $this->calculateScore($avgComplexity, count($highComplexity), $methodCount, count($deepNesting), $duplicateCount);

        $this->generateComplexityRecommendations($avgComplexity, $highComplexity, $deepNesting, $duplicateCount);

        return $this->buildResult($score, [
            'total_methods'        => $methodCount,
            'avg_cyclomatic_complexity' => round($avgComplexity, 2),
            'high_complexity_methods'   => count($highComplexity),
            'max_complexity'       => !empty($allComplexities) ? $allComplexities[0]['complexity'] : 0,
            'deep_nesting_classes' => count($deepNesting),
            'long_classes'         => count($longClasses),
            'duplicate_blocks'     => $duplicateCount,
            'complexity_distribution' => [
                'low'      => count(array_filter($allComplexities, fn($c) => $c['complexity'] <= self::CYCLOMATIC_LOW)),
                'medium'   => count(array_filter($allComplexities, fn($c) => $c['complexity'] > self::CYCLOMATIC_LOW && $c['complexity'] <= self::CYCLOMATIC_MEDIUM)),
                'high'     => count(array_filter($allComplexities, fn($c) => $c['complexity'] > self::CYCLOMATIC_MEDIUM && $c['complexity'] <= self::CYCLOMATIC_HIGH)),
                'critical' => count(array_filter($allComplexities, fn($c) => $c['complexity'] > self::CYCLOMATIC_HIGH)),
            ],
            'most_complex_methods' => array_slice($allComplexities, 0, 5),
        ], sprintf("CC promedio: %.2f. Métodos de alta complejidad: %d.", $avgComplexity, count($highComplexity)) .
           ". Anidamiento profundo: " . count($deepNesting) . " clases. Duplicación: {$duplicateCount} bloques.");
    }

    private function extractMethods(string $content): array
    {
        $methods = [];
        $pattern = '/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\([^{]*\)\s*(?::\s*[\w|?\\\\]+\s*)?\{/';

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $methods;
        }

        $contentLength = strlen($content);

        foreach ($matches[0] as $idx => $match) {
            $methodName = $matches[1][$idx][0];
            $startPos   = $match[1] + strlen($match[0]) - 1; // Start at opening {

            // Extract method body via brace matching
            $body  = '';
            $depth = 0;
            $inStr = false;
            $strChar = '';

            for ($i = $startPos; $i < $contentLength; $i++) {
                $char = $content[$i];

                if (!$inStr && ($char === '"' || $char === "'")) {
                    $inStr = true;
                    $strChar = $char;
                } elseif ($inStr && $char === $strChar && ($i === 0 || $content[$i-1] !== '\\')) {
                    $inStr = false;
                } elseif (!$inStr) {
                    if ($char === '{') $depth++;
                    elseif ($char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $body = substr($content, $startPos, $i - $startPos + 1);
                            break;
                        }
                    }
                }
            }

            if ($body) {
                $methods[] = ['name' => $methodName, 'body' => $body];
            }
        }

        return $methods;
    }

    private function calculateCyclomaticComplexity(string $code): int
    {
        $complexity = 1; // Base complexity

        // Remove strings to avoid false positives
        $code = preg_replace('/["\'][^"\']*["\']/', '""', $code);
        // Remove comments
        $code = preg_replace('/\/\/[^\n]*\n/', "\n", $code);
        $code = preg_replace('/\/\*[\s\S]*?\*\//', '', $code);

        foreach (self::COMPLEXITY_PATTERNS as $pattern => $weight) {
            $count = preg_match_all($pattern, $code, $m);
            $complexity += $count * $weight;
        }

        return $complexity;
    }

    private function calculateMaxNesting(string $content): int
    {
        $maxDepth    = 0;
        $currentDepth = 0;
        $inString    = false;
        $strChar     = '';

        // Nesting control structures
        $openPatterns  = ['if', 'for', 'foreach', 'while', 'switch', 'try'];
        $closePattern  = '}';

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $stripped = trim($line);

            // Skip comments
            if (str_starts_with($stripped, '//') || str_starts_with($stripped, '*')) continue;

            foreach ($openPatterns as $keyword) {
                if (preg_match('/\b' . $keyword . '\s*[\(\{]/', $stripped)) {
                    $currentDepth++;
                    $maxDepth = max($maxDepth, $currentDepth);
                }
            }

            if (str_contains($stripped, '}') && $currentDepth > 0) {
                $currentDepth -= substr_count($stripped, '}');
                $currentDepth = max(0, $currentDepth);
            }
        }

        return $maxDepth;
    }

    private function detectDuplication(array $files, string $projectPath): int
    {
        $duplicates   = 0;
        $codeBlocks   = [];
        $blockSize    = 6; // Minimum lines to consider a duplicate

        foreach ($files as $file) {
            $lines = explode("\n", $this->readFile($file));
            $lineCount = count($lines);

            for ($i = 0; $i <= $lineCount - $blockSize; $i++) {
                $block = implode("\n", array_slice($lines, $i, $blockSize));
                $block = trim(preg_replace('/\s+/', ' ', $block));

                if (strlen($block) < 100) continue; // Skip short/empty blocks

                $hash = md5($block);
                if (isset($codeBlocks[$hash])) {
                    $duplicates++;
                } else {
                    $codeBlocks[$hash] = $file;
                }
            }
        }

        return $duplicates;
    }

    private function calculateScore(float $avgCC, int $highCount, int $totalMethods, int $deepNesting, int $duplicates): float
    {
        $score = 100;

        // Penalize by average complexity
        if ($avgCC > self::CYCLOMATIC_HIGH) {
            $score -= 40;
        } elseif ($avgCC > self::CYCLOMATIC_MEDIUM) {
            $score -= 20;
        } elseif ($avgCC > self::CYCLOMATIC_LOW) {
            $score -= 10;
        }

        // Penalize high complexity methods
        $highRatio = $totalMethods > 0 ? ($highCount / $totalMethods) * 100 : 0;
        $score -= $highRatio * 0.5;

        // Penalize deep nesting
        $score -= $deepNesting * 3;

        // Penalize duplication
        $score -= min(30, $duplicates * 0.5);

        return max(0, min(100, $score));
    }

    private function generateComplexityRecommendations(float $avgCC, array $highComplexity, array $deepNesting, int $duplicates): void
    {
        if ($avgCC > self::CYCLOMATIC_MEDIUM) {
            $this->addRecommendation("Apunta a una Complejidad Ciclomática promedio < " . self::CYCLOMATIC_MEDIUM . ". Descompón métodos complejos en más pequeños.");
        }

        if (!empty($highComplexity)) {
            $worst = $highComplexity[0];
            $this->addRecommendation("El método más complejo es '{$worst['class']}::{$worst['method']}' (CC={$worst['complexity']}). " .
                "Prioriza refactorizarlo: extrae métodos, usa Strategy pattern o simplifica condicionales.");
        }

        if (!empty($deepNesting)) {
            $this->addRecommendation("Reduce el anidamiento profundo usando 'Return Early' (guard clauses): retorna o lanza excepciones pronto en lugar de anidar if/else.");
        }

        if ($duplicates > 20) {
            $this->addRecommendation("Alta duplicación de código detectada ({$duplicates} bloques). Aplica el principio DRY: extrae métodos, traits, o helpers compartidos.");
        }

        $this->addRecommendation("Instala PHPStan con Larastan para detección automática de complejidad: 'composer require --dev nunomaduro/larastan'.");
        $this->addRecommendation("Configura un límite de complejidad en CI/CD. PHPStan y PHPMD pueden rechazar código con CC > 10 automáticamente.");
    }
}
