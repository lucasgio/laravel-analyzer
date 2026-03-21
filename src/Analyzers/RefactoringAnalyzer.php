<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Detects refactoring opportunities in developer-written Laravel code.
 *
 * Covers:
 *  - SOLID violations (SRP, OCP, DIP)
 *  - DRY violations (duplicated logic blocks)
 *  - Missing Dependency Injection (new inside business classes, app()/resolve())
 *  - Laravel Actions pattern (fat controllers, missing single-action __invoke)
 */
class RefactoringAnalyzer extends BaseAnalyzer
{
    public function analyze(string $projectPath): array
    {
        $this->projectPath  = $projectPath;
        $this->issues       = [];
        $this->fileIssues   = [];
        $this->recommendations = [];

        $files = $this->getDevPhpFiles($projectPath . '/app');

        $metrics = [
            'files_analyzed'          => count($files),
            'srp_violations'          => 0,
            'ocp_violations'          => 0,
            'dip_violations'          => 0,
            'missing_di_occurrences'  => 0,
            'fat_controllers'         => 0,
            'single_action_candidates' => 0,
            'dry_violations'          => 0,
        ];

        $methodHashes = []; // for DRY detection across files

        foreach ($files as $file) {
            $content  = $this->readFile($file);
            $relative = $this->toRelative($file, $projectPath);

            $this->checkSRP($content, $relative, $metrics);
            $this->checkOCP($content, $relative, $metrics);
            $this->checkDIP($content, $relative, $metrics);
            $this->checkMissingDI($content, $relative, $metrics);
            $this->checkLaravelActions($content, $relative, $metrics);
            $this->collectMethodHashes($content, $relative, $methodHashes);
        }

        $this->detectDryViolations($methodHashes, $metrics);

        $score = $this->calculateScore($metrics, count($files));
        $this->generateRecommendations($metrics);

        return $this->buildResult(
            $score,
            $metrics,
            "Files analyzed: {$metrics['files_analyzed']}. " .
            "SRP: {$metrics['srp_violations']}, DIP: {$metrics['dip_violations']}, " .
            "Fat controllers: {$metrics['fat_controllers']}, DRY: {$metrics['dry_violations']}."
        );
    }

    // ------------------------------------------------------------------
    // SOLID — Single Responsibility Principle
    // ------------------------------------------------------------------

    private function checkSRP(string $content, string $file, array &$metrics): void
    {
        // Heuristic: a class touches DB (Eloquent/DB), sends HTTP responses,
        // AND does mail/event dispatching → multiple responsibilities
        $hasDb      = preg_match('/\b(DB::|Eloquent|->where\(|->save\(|->create\(|->update\(|->delete\()/i', $content);
        $hasHttp    = preg_match('/\b(response\(|redirect\(|view\(|return response|JsonResponse)/i', $content);
        $hasMail    = preg_match('/\b(Mail::|Notification::|Event::|dispatch\(|sendNow\()/i', $content);
        $hasStorage = preg_match('/\b(Storage::|File::|UploadedFile)/i', $content);

        $responsibilities = (int)$hasDb + (int)$hasHttp + (int)$hasMail + (int)$hasStorage;

        if ($responsibilities >= 3) {
            $metrics['srp_violations']++;
            $className = $this->extractClassName($content) ?? basename($file, '.php');
            $this->addIssue(
                'MEDIUM',
                $file,
                "[SRP] '{$className}' handles {$responsibilities} concerns (DB, HTTP, Mail/Events, Storage). " .
                "Consider splitting into dedicated classes (Service, Action, Listener)."
            );
        }
    }

    // ------------------------------------------------------------------
    // SOLID — Open/Closed Principle
    // ------------------------------------------------------------------

    private function checkOCP(string $content, string $file, array &$metrics): void
    {
        // Large switch statements or if/elseif chains enumerating types
        // suggest the class must be modified each time a new type is added.
        preg_match_all('/switch\s*\(/', $content, $switches);
        $switchCount = count($switches[0]);

        // Count elseif chains (≥3 elseif = code smell)
        preg_match_all('/elseif\s*\(/', $content, $elseifs);
        $elseifCount = count($elseifs[0]);

        if ($switchCount >= 2 || $elseifCount >= 4) {
            $metrics['ocp_violations']++;
            $className = $this->extractClassName($content) ?? basename($file, '.php');
            $this->addIssue(
                'LOW',
                $file,
                "[OCP] '{$className}' contains " .
                ($switchCount >= 2 ? "{$switchCount} switch statements" : "{$elseifCount} elseif branches") .
                ". Consider using polymorphism or the Strategy pattern to avoid modifying this class for new types."
            );
        }
    }

    // ------------------------------------------------------------------
    // SOLID — Dependency Inversion Principle
    // ------------------------------------------------------------------

    private function checkDIP(string $content, string $file, array &$metrics): void
    {
        // Type-hints to concrete classes in constructor parameters
        // e.g. __construct(UserRepository $repo) instead of UserRepositoryInterface $repo
        if (!preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $content, $m)) {
            return;
        }

        $params = $m[1];

        // Find type-hints that are concrete (PascalCase, no Interface/Contract suffix)
        preg_match_all('/([A-Z][a-zA-Z]+)\s+\$\w+/', $params, $types);

        $concreteTypes = array_filter($types[1], fn(string $t) =>
            !str_ends_with($t, 'Interface') &&
            !str_ends_with($t, 'Contract') &&
            !in_array($t, ['Request', 'Response', 'Collection', 'Builder', 'Carbon', 'Closure', 'string', 'int', 'array', 'bool'])
        );

        if (count($concreteTypes) >= 2) {
            $metrics['dip_violations']++;
            $className = $this->extractClassName($content) ?? basename($file, '.php');
            $this->addIssue(
                'LOW',
                $file,
                "[DIP] '{$className}' injects " . count($concreteTypes) . " concrete classes (" .
                implode(', ', array_slice($concreteTypes, 0, 3)) .
                "). Define interfaces/contracts for dependencies to improve testability."
            );
        }
    }

    // ------------------------------------------------------------------
    // Dependency Injection — detect manual instantiation and service locator
    // ------------------------------------------------------------------

    private function checkMissingDI(string $content, string $file, array &$metrics): void
    {
        $className = $this->extractClassName($content) ?? '';

        // Skip providers and bootstrappers — they legitimately use new/app()
        if (str_contains($file, 'Provider') || str_contains($file, 'bootstrap')) {
            return;
        }

        // new ConcreteClass() inside methods (not in constructors or factory methods)
        preg_match_all('/=\s*new\s+([A-Z][a-zA-Z]+)\s*\(/', $content, $news);
        $instantiations = array_filter($news[1], fn(string $c) =>
            !in_array($c, ['Carbon', 'DateTime', 'stdClass', 'ArrayObject', 'Exception', 'InvalidArgumentException'])
        );

        if (count($instantiations) >= 1) {
            $metrics['missing_di_occurrences'] += count($instantiations);
            foreach (array_slice($instantiations, 0, 3) as $class) {
                $this->addIssue(
                    'MEDIUM',
                    $file,
                    "[DI] 'new {$class}()' found in '{$className}'. Inject via constructor instead to decouple and enable testing."
                );
            }
        }

        // Service locator anti-pattern: app() / resolve() outside providers
        preg_match_all('/\b(app\(|resolve\(|App::make\(|Container::getInstance\()/', $content, $locators);
        if (count($locators[0]) >= 2) {
            $metrics['missing_di_occurrences']++;
            $this->addIssue(
                'LOW',
                $file,
                "[DI] '{$className}' uses the service locator (" . implode(', ', array_unique($locators[1])) . ") " .
                count($locators[0]) . " times. Prefer constructor injection over service locator calls."
            );
        }
    }

    // ------------------------------------------------------------------
    // Laravel Actions pattern
    // ------------------------------------------------------------------

    private function checkLaravelActions(string $content, string $file, array &$metrics): void
    {
        $isController = str_contains($file, 'Controller') ||
                        str_contains($content, 'extends Controller') ||
                        str_contains($content, 'extends BaseController');

        if (!$isController) {
            return;
        }

        $className = $this->extractClassName($content) ?? basename($file, '.php');

        // Count public methods
        preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $pubMethods);
        $publicMethods = array_filter($pubMethods[1], fn(string $m) =>
            !in_array($m, ['__construct', '__invoke', 'middleware', 'callAction', 'getMiddleware'])
        );
        $methodCount = count($publicMethods);

        // Fat controller — methods with substantial business logic (> 15 lines each on average)
        $lines       = substr_count($content, "\n");
        $avgLines    = $methodCount > 0 ? $lines / $methodCount : 0;

        if ($avgLines > 20 && $methodCount >= 2) {
            $metrics['fat_controllers']++;
            $this->addIssue(
                'MEDIUM',
                $file,
                "[Actions] '{$className}' is a fat controller (~{$avgLines} lines/method avg, {$methodCount} public methods). " .
                "Extract business logic into Action classes (e.g., CreateUserAction, UpdateOrderAction)."
            );
        }

        // Single-action controller candidate
        if ($methodCount === 1 && !str_contains($content, '__invoke')) {
            $metrics['single_action_candidates']++;
            $method = reset($publicMethods);
            $this->addIssue(
                'LOW',
                $file,
                "[Actions] '{$className}' has a single public method '{$method}'. " .
                "Consider converting to a single-action controller using __invoke() for cleaner routing."
            );
        }
    }

    // ------------------------------------------------------------------
    // DRY — collect normalized method body hashes for cross-file comparison
    // ------------------------------------------------------------------

    private function collectMethodHashes(string $content, string $file, array &$hashes): void
    {
        // Extract method bodies (simplified — finds content between braces)
        preg_match_all(
            '/(?:public|protected|private)\s+function\s+\w+\s*\([^)]*\)\s*(?::\s*\S+\s*)?\{([^{}]{80,})\}/s',
            $content,
            $matches
        );

        foreach ($matches[1] as $body) {
            // Normalize: strip whitespace, variable names, string literals
            $normalized = preg_replace(['/\$\w+/', '/["\'][^"\']*["\']/', '/\s+/'], ['$v', '""', ' '], trim($body));
            $hash       = md5($normalized);

            if (strlen($normalized) < 120) {
                continue; // too small to be a meaningful duplicate
            }

            $hashes[$hash][] = $file;
        }
    }

    private function detectDryViolations(array $hashes, array &$metrics): void
    {
        foreach ($hashes as $hash => $files) {
            if (count($files) < 2) {
                continue;
            }

            $metrics['dry_violations']++;
            $fileList = implode(', ', array_map('basename', array_slice($files, 0, 3)));
            $count    = count($files);

            // Report on the first occurrence file
            $this->addIssue(
                'LOW',
                $files[0],
                "[DRY] Duplicated logic block detected in {$count} files ({$fileList}). " .
                "Extract to a shared method, trait, or helper class."
            );
        }
    }

    // ------------------------------------------------------------------
    // Score & Recommendations
    // ------------------------------------------------------------------

    private function calculateScore(array $metrics, int $totalFiles): float
    {
        if ($totalFiles === 0) {
            return 100.0;
        }

        $score = 100.0;

        $score -= min(25, $metrics['srp_violations'] * 5);
        $score -= min(10, $metrics['ocp_violations'] * 2);
        $score -= min(10, $metrics['dip_violations'] * 2);
        $score -= min(20, $metrics['missing_di_occurrences'] * 2);
        $score -= min(20, $metrics['fat_controllers'] * 8);
        $score -= min(10, $metrics['dry_violations'] * 3);
        $score -= min(5,  $metrics['single_action_candidates'] * 1);

        return max(0.0, $score);
    }

    private function generateRecommendations(array $metrics): void
    {
        if ($metrics['srp_violations'] > 0) {
            $this->addRecommendation(
                "SRP — Extract business logic from classes mixing DB, HTTP, Mail and Storage concerns. " .
                "Use dedicated Service or Action classes per responsibility."
            );
        }

        if ($metrics['missing_di_occurrences'] > 0) {
            $this->addRecommendation(
                "DI — Replace 'new ClassName()' and app()/resolve() inside business classes with constructor injection. " .
                "Register bindings in a ServiceProvider."
            );
        }

        if ($metrics['fat_controllers'] > 0) {
            $this->addRecommendation(
                "Laravel Actions — Move business logic out of fat controllers into single-purpose Action classes. " .
                "Each Action should have one public method (handle() or __invoke()) and do one thing."
            );
        }

        if ($metrics['single_action_candidates'] > 0) {
            $this->addRecommendation(
                "Single-Action Controllers — Convert controllers with a single public method to use __invoke(). " .
                "Register with Route::get('/path', MyController::class)."
            );
        }

        if ($metrics['dry_violations'] > 0) {
            $this->addRecommendation(
                "DRY — Duplicated logic blocks found. Extract repeated code into traits, helper classes, or base classes."
            );
        }

        if ($metrics['dip_violations'] > 0) {
            $this->addRecommendation(
                "DIP — Define interfaces for injectable dependencies. Bind them in a ServiceProvider: " .
                "\$this->app->bind(MyInterface::class, MyConcreteClass::class)."
            );
        }
    }

    private function toRelative(string $absolute, string $projectPath): string
    {
        $base = rtrim($projectPath, '/') . '/';
        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }
}
