<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analiza el proyecto contra el OWASP Top 10 (2021).
 *
 * A01: Broken Access Control
 * A02: Cryptographic Failures
 * A03: Injection
 * A04: Insecure Design
 * A05: Security Misconfiguration
 * A06: Vulnerable & Outdated Components
 * A07: Identification & Authentication Failures
 * A08: Software & Data Integrity Failures
 * A09: Security Logging & Monitoring Failures
 * A10: Server-Side Request Forgery (SSRF)
 */
class OwaspAnalyzer extends BaseAnalyzer
{
    private array $owaspResults = [];

    public function analyze(string $projectPath): array
    {
        $this->projectPath = $projectPath;
        $this->issues = [];
        $this->recommendations = [];

        $this->checkA01BrokenAccessControl($projectPath);
        $this->checkA02CryptographicFailures($projectPath);
        $this->checkA03Injection($projectPath);
        $this->checkA04InsecureDesign($projectPath);
        $this->checkA05SecurityMisconfiguration($projectPath);
        $this->checkA06VulnerableComponents($projectPath);
        $this->checkA07AuthFailures($projectPath);
        $this->checkA08IntegrityFailures($projectPath);
        $this->checkA09LoggingFailures($projectPath);
        $this->checkA10SSRF($projectPath);

        // Calculate overall OWASP score
        $scores = array_column($this->owaspResults, 'score');
        $avgScore = !empty($scores) ? array_sum($scores) / count($scores) : 0;

        $criticalOwaspItems = count(array_filter($this->owaspResults, fn($r) => $r['score'] < 40));
        $totalFindings = array_sum(array_column($this->owaspResults, 'findings_count'));

        return $this->buildResult($avgScore, [
            'owasp_breakdown'      => $this->owaspResults,
            'total_findings'       => $totalFindings,
            'critical_owasp_items' => $criticalOwaspItems,
            'passed_checks'        => count(array_filter($this->owaspResults, fn($r) => $r['score'] >= 80)),
        ], "OWASP Score: " . round($avgScore, 1) . "/100. Categorías críticas: {$criticalOwaspItems}/10. " .
           "Total hallazgos: {$totalFindings}.");
    }

    private function checkA01BrokenAccessControl(string $projectPath): void
    {
        $findings = 0;
        $files = $this->getPhpFiles($projectPath . '/app');

        // Check for policies
        $policyPath  = $projectPath . '/app/Policies';
        $hasPolicies = is_dir($policyPath) && count($this->getPhpFiles($policyPath)) > 0;
        if (!$hasPolicies) {
            $findings++;
            $this->addIssue('HIGH', 'app/Policies', "[A01] Sin Policies de autorización. Implementa Gates y Policies para control de acceso granular.");
        }

        // Check for missing authorization in controllers
        foreach ($files as $file) {
            $content = $this->readFile($file);
            if (!str_contains($file, 'Controller')) continue;

            $hasAuth = str_contains($content, '$this->authorize') ||
                       str_contains($content, 'Gate::') ||
                       str_contains($content, 'can(') ||
                       str_contains($content, 'cannot(') ||
                       str_contains($content, '@authorize') ||
                       str_contains($content, 'middleware(\'can:');

            $hasActions = preg_match_all('/public\s+function\s+(?!__construct)(\w+)\s*\(/', $content, $m) > 3;

            $relativePath = str_replace($projectPath . '/', '', $file);
            if ($hasActions && !$hasAuth) {
                $findings++;
                $this->addIssue('MEDIUM', $relativePath,
                    "[A01] Controlador sin checks de autorización visibles. Verifica que usa Policies o Gate::authorize().");
            }
        }

        // Check for IDOR risks (direct model usage without ownership check)
        foreach ($files as $file) {
            $content = $this->readFile($file);
            if (!str_contains($file, 'Controller')) continue;
            $relativePath = str_replace($projectPath . '/', '', $file);

            if (preg_match('/\b\w+::find\s*\(\s*\$(?:request|id|[\w]+Id)\b/', $content)) {
                $hasOwnerCheck = str_contains($content, '->user_id') ||
                                 str_contains($content, 'whereUserId') ||
                                 str_contains($content, '->authorize') ||
                                 str_contains($content, 'Gate::');
                if (!$hasOwnerCheck) {
                    $findings++;
                    $this->addIssue('HIGH', $relativePath,
                        "[A01] Posible IDOR: Model::find(\$id) sin verificación de ownership. " .
                        "Verifica que el usuario tiene acceso al recurso: Route Model Binding con Policy.");
                }
            }
        }

        $score = max(0, 100 - ($findings * 15));
        $this->addOwaspResult('A01', 'Broken Access Control', $score, $findings,
            'Control de acceso deficiente permite a usuarios realizar acciones no autorizadas.');
    }

    private function checkA02CryptographicFailures(string $projectPath): void
    {
        $findings = 0;
        $files = $this->getPhpFiles($projectPath);

        foreach ($files as $file) {
            $content      = $this->readFile($file);
            $relativePath = str_replace($projectPath . '/', '', $file);

            // Weak hashing
            if (preg_match_all('/\bmd5\s*\(\s*\$(?:password|pass|pwd|secret|token)/i', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('CRITICAL', $relativePath,
                    "[A02] MD5 para datos sensibles. Usa Hash::make() (bcrypt/argon2) para contraseñas.");
            }

            // Hardcoded secrets
            if (preg_match_all('/(?:password|secret|api_?key|token|private_?key)\s*=\s*["\'][^"\']{8,}["\']/', $content, $m)) {
                foreach ($m[0] as $match) {
                    if (!str_contains($match, 'env(') && !str_contains($match, 'config(')) {
                        $findings++;
                        $this->addIssue('CRITICAL', $relativePath,
                            "[A02] Posible secreto hardcodeado detectado. Mueve todos los secretos a variables de entorno (.env).");
                    }
                }
            }

            // HTTP links (not HTTPS) in code
            if (preg_match_all('/["\']http:\/\/(?!localhost|127\.0\.0\.1)/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('LOW', $relativePath,
                    "[A02] URL HTTP encontrada (no segura). Usa HTTPS para todas las comunicaciones externas.");
            }
        }

        // Check if HTTPS is enforced
        $appConfig = $projectPath . '/app/Providers/AppServiceProvider.php';
        if (file_exists($appConfig)) {
            $content = $this->readFile($appConfig);
            if (!str_contains($content, 'URL::forceScheme') && !str_contains($content, 'forceHttps')) {
                $findings++;
                $this->addIssue('MEDIUM', 'app/Providers/AppServiceProvider.php',
                    "[A02] No se detecta URL::forceScheme('https'). Agrégate en producción para forzar HTTPS.");
            }
        }

        $score = max(0, 100 - ($findings * 12));
        $this->addOwaspResult('A02', 'Cryptographic Failures', $score, $findings,
            'Exposición de datos sensibles por fallos criptográficos o datos en texto plano.');
    }

    private function checkA03Injection(string $projectPath): void
    {
        $findings = 0;
        $files = $this->getPhpFiles($projectPath);

        foreach ($files as $file) {
            $content      = $this->readFile($file);
            $relativePath = str_replace($projectPath . '/', '', $file);

            // SQL injection
            if (preg_match_all('/DB::(?:select|statement)\s*\(\s*["\'][^"\']*\.\s*\$/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('CRITICAL', $relativePath, "[A03] SQL Injection: concatenación en query cruda.");
            }

            // Command injection
            if (preg_match_all('/\b(?:exec|shell_exec|system|passthru)\s*\(\s*.*\$/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('CRITICAL', $relativePath, "[A03] Command Injection: ejecución de sistema con variable.");
            }

            // LDAP injection (if using LDAP)
            if (preg_match_all('/ldap_search\s*\([^,]+,\s*[^,]+,\s*.*\$/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('CRITICAL', $relativePath, "[A03] LDAP Injection posible.");
            }

            // Object injection via unserialize
            if (preg_match_all('/\bunserialize\s*\(\s*\$(?:request|input|data|payload)/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('CRITICAL', $relativePath,
                    "[A03] Object Injection: unserialize() con datos del usuario es extremadamente peligroso. Usa JSON.");
            }
        }

        $score = max(0, 100 - ($findings * 20));
        $this->addOwaspResult('A03', 'Injection', $score, $findings,
            'Datos del usuario enviados a intérpretes como SQL, OS, o LDAP sin sanitización.');
    }

    private function checkA04InsecureDesign(string $projectPath): void
    {
        $findings = 0;

        // Check for rate limiting on auth routes
        $routesFile = $projectPath . '/routes/web.php';
        $apiRoutes  = $projectPath . '/routes/api.php';

        foreach ([$routesFile, $apiRoutes] as $routeFile) {
            if (!file_exists($routeFile)) continue;
            $content = $this->readFile($routeFile);

            if (!str_contains($content, 'throttle') && !str_contains($content, 'RateLimiter')) {
                $findings++;
                $relativePath = str_replace($projectPath . '/', '', $routeFile);
                $this->addIssue('HIGH', $relativePath,
                    "[A04] Sin rate limiting en rutas. Agrega middleware 'throttle:60,1' para prevenir brute force.");
            }
        }

        // Check for validation on all form requests
        $controllersPath = $projectPath . '/app/Http/Controllers';
        if (is_dir($controllersPath)) {
            foreach ($this->getPhpFiles($controllersPath) as $file) {
                $content = $this->readFile($file);
                $relativePath = str_replace($projectPath . '/', '', $file);

                // Check for store/update methods without validation
                if (preg_match('/public\s+function\s+(?:store|update)\s*\(/', $content)) {
                    $hasValidation = str_contains($content, 'validate(') ||
                                     str_contains($content, 'FormRequest') ||
                                     str_contains($content, 'Validator::');
                    if (!$hasValidation) {
                        $findings++;
                        $this->addIssue('HIGH', $relativePath,
                            "[A04] Método store/update sin validación visible. Usa Form Request Validation o \$request->validate().");
                    }
                }
            }
        }

        $score = max(0, 100 - ($findings * 10));
        $this->addOwaspResult('A04', 'Insecure Design', $score, $findings,
            'Falta de controles de diseño seguro: rate limiting, validación, threat modeling.');
    }

    private function checkA05SecurityMisconfiguration(string $projectPath): void
    {
        $findings = 0;

        // Check .env
        $envFile = $projectPath . '/.env';
        if (file_exists($envFile)) {
            $env = $this->readFile($envFile);

            if (preg_match('/APP_DEBUG\s*=\s*true/i', $env)) {
                $findings += 2;
                $this->addIssue('CRITICAL', '.env', "[A05] APP_DEBUG=true. Deshabilita en producción.");
            }

            if (!preg_match('/SESSION_DRIVER\s*=\s*(?:database|redis|memcached)/i', $env)) {
                $this->addIssue('LOW', '.env', "[A05] SESSION_DRIVER=file puede ser inseguro en servidores compartidos. Usa 'database' o 'redis'.");
            }
        }

        // Check config/session.php
        $sessionConfig = $projectPath . '/config/session.php';
        if (file_exists($sessionConfig)) {
            $content = $this->readFile($sessionConfig);
            if (!str_contains($content, "'same_site' => 'lax'") && !str_contains($content, "'same_site' => 'strict'")) {
                $findings++;
                $this->addIssue('MEDIUM', 'config/session.php',
                    "[A05] Cookie SameSite no configurado como 'lax' o 'strict'. Protege contra CSRF.");
            }
        }

        // Check for exposed .env
        $gitignore = $projectPath . '/.gitignore';
        if (file_exists($gitignore)) {
            $content = $this->readFile($gitignore);
            if (!str_contains($content, '.env')) {
                $findings++;
                $this->addIssue('CRITICAL', '.gitignore', "[A05] .env no está en .gitignore. Tus secretos podrían estar en el repositorio.");
            }
        }

        $score = max(0, 100 - ($findings * 12));
        $this->addOwaspResult('A05', 'Security Misconfiguration', $score, $findings,
            'Configuración de seguridad incorrecta en la aplicación, framework o servidor.');
    }

    private function checkA06VulnerableComponents(string $projectPath): void
    {
        $findings = 0;

        $composerFile = $projectPath . '/composer.json';
        $lockFile     = $projectPath . '/composer.lock';

        if (!file_exists($composerFile)) {
            $this->addOwaspResult('A06', 'Vulnerable & Outdated Components', 50, 1,
                'No se pudo analizar dependencias (composer.json no encontrado).');
            return;
        }

        $composer = json_decode($this->readFile($composerFile), true) ?? [];
        $deps = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        // Check for wildcard versions
        foreach ($deps as $pkg => $ver) {
            if (in_array($ver, ['*', '@dev', 'dev-master'])) {
                $findings++;
                $this->addIssue('HIGH', 'composer.json',
                    "[A06] Dependencia '{$pkg}' sin versión fija ({$ver}). Fija la versión para evitar actualizaciones automáticas con vulnerabilidades.");
            }
        }

        // Check for missing composer.lock
        if (!file_exists($lockFile)) {
            $findings++;
            $this->addIssue('HIGH', 'composer.lock',
                "[A06] composer.lock no encontrado. Sin él, las dependencias pueden cambiar entre instalaciones.");
        }

        // Check PHP version requirement
        if (isset($composer['require']['php'])) {
            $phpReq = $composer['require']['php'];
            if (preg_match('/^(?:\^|~|>=?)?\s*([0-9]+\.[0-9]+)/', $phpReq, $m)) {
                $minVersion = (float)$m[1];
                if ($minVersion < 8.1) {
                    $findings++;
                    $this->addIssue('HIGH', 'composer.json',
                        "[A06] Versión mínima de PHP {$phpReq} es obsoleta. PHP 8.1+ recibe actualizaciones de seguridad activas.");
                }
            }
        }

        $this->addRecommendation("Ejecuta 'composer audit' regularmente para detectar CVEs en dependencias. Integra en CI/CD.");

        $score = max(0, 100 - ($findings * 15));
        $this->addOwaspResult('A06', 'Vulnerable & Outdated Components', $score, $findings,
            'Componentes, librerías y frameworks con vulnerabilidades conocidas sin actualizar.');
    }

    private function checkA07AuthFailures(string $projectPath): void
    {
        $findings = 0;

        // Check for password policy
        $authConfig = $projectPath . '/config/auth.php';
        $hasPasswordPolicy = false;

        if (is_dir($projectPath . '/app/Rules')) {
            foreach ($this->getPhpFiles($projectPath . '/app/Rules') as $file) {
                if (str_contains(strtolower($file), 'password')) {
                    $hasPasswordPolicy = true;
                    break;
                }
            }
        }

        // Check for session regeneration after login
        foreach ($this->getPhpFiles($projectPath . '/app') as $file) {
            $content = $this->readFile($file);
            if (str_contains($content, 'Auth::login') || str_contains($content, 'auth()->login')) {
                if (!str_contains($content, 'session()->regenerate') && !str_contains($content, 'regenerateToken')) {
                    $relativePath = str_replace($projectPath . '/', '', $file);
                    $findings++;
                    $this->addIssue('HIGH', $relativePath,
                        "[A07] Login sin session()->regenerate(). Puede llevar a Session Fixation attacks.");
                }
            }
        }

        // Check for MFA (simple check)
        $composerJson = $projectPath . '/composer.json';
        if (file_exists($composerJson)) {
            $content = $this->readFile($composerFile = $composerJson);
            $hasMfa = str_contains($content, 'pragmarx/google2fa') ||
                      str_contains($content, 'laravel/fortify') ||
                      str_contains($content, 'filament/fortify');
            if (!$hasMfa) {
                $this->addIssue('MEDIUM', 'composer.json',
                    "[A07] Sin MFA detectado. Considera implementar autenticación de dos factores (2FA) con laravel/fortify.");
            }
        }

        $score = max(0, 100 - ($findings * 15));
        $this->addOwaspResult('A07', 'Identification & Authentication Failures', $score, $findings,
            'Fallas en la identificación y autenticación de usuarios.');
    }

    private function checkA08IntegrityFailures(string $projectPath): void
    {
        $findings = 0;

        // Check for CI/CD config with security steps
        $ciFiles = ['.github/workflows', '.gitlab-ci.yml', 'Jenkinsfile', '.circleci/config.yml'];
        $hasCi = false;
        foreach ($ciFiles as $ci) {
            if (file_exists($projectPath . '/' . $ci) || is_dir($projectPath . '/' . $ci)) {
                $hasCi = true;
                break;
            }
        }

        if (!$hasCi) {
            $findings++;
            $this->addIssue('MEDIUM', '/',
                "[A08] Sin configuración de CI/CD detectada. Implementa pipelines con 'composer audit' y tests automáticos.");
        }

        // Check deserialization
        foreach ($this->getPhpFiles($projectPath) as $file) {
            $content = $this->readFile($file);
            if (preg_match_all('/\bunserialize\s*\(/', $content, $m)) {
                $relativePath = str_replace($projectPath . '/', '', $file);
                $findings += count($m[0]);
                $this->addIssue('HIGH', $relativePath,
                    "[A08] Uso de unserialize(). Los datos deserializados pueden llevar a Remote Code Execution. Usa JSON::decode() en su lugar.");
            }
        }

        $score = max(0, 100 - ($findings * 20));
        $this->addOwaspResult('A08', 'Software & Data Integrity Failures', $score, $findings,
            'Código e infraestructura sin protección contra actualizaciones maliciosas.');
    }

    private function checkA09LoggingFailures(string $projectPath): void
    {
        $findings = 0;

        // Check for logging configuration
        $loggingConfig = $projectPath . '/config/logging.php';
        $hasLogging    = file_exists($loggingConfig);

        if (!$hasLogging) {
            $findings++;
            $this->addIssue('HIGH', 'config/logging.php',
                "[A09] Sin configuración de logging detectada. El logging es crítico para detectar y responder a incidentes.");
        }

        // Check for security event logging
        $authEvents = false;
        foreach ($this->getPhpFiles($projectPath . '/app') as $file) {
            $content = $this->readFile($file);
            if (preg_match('/Log::(?:warning|error|critical)\s*\(.*(?:login|auth|access|fail)/i', $content)) {
                $authEvents = true;
                break;
            }
        }

        if (!$authEvents) {
            $findings++;
            $this->addIssue('MEDIUM', 'app/',
                "[A09] Sin logging de eventos de seguridad (fallos de login, acceso no autorizado). " .
                "Agrega Log::warning() en AuthController y handlers de autorización.");
        }

        // Check for log injection
        foreach ($this->getPhpFiles($projectPath) as $file) {
            $content = $this->readFile($file);
            if (preg_match('/Log::\w+\s*\(\s*\$(?:request|input|data)\b/', $content)) {
                $relativePath = str_replace($projectPath . '/', '', $file);
                $findings++;
                $this->addIssue('MEDIUM', $relativePath,
                    "[A09] Posible Log Injection: logging de datos crudos del usuario. Sanitiza antes de loggear.");
            }
        }

        $score = max(0, 100 - ($findings * 15));
        $this->addOwaspResult('A09', 'Security Logging & Monitoring Failures', $score, $findings,
            'Falta de logging, monitoreo y alertas para detectar y responder a brechas de seguridad.');
    }

    private function checkA10SSRF(string $projectPath): void
    {
        $findings = 0;

        foreach ($this->getPhpFiles($projectPath) as $file) {
            $content      = $this->readFile($file);
            $relativePath = str_replace($projectPath . '/', '', $file);

            // Check for HTTP requests with user-controlled URLs
            if (preg_match_all('/Http::(?:get|post|put|patch|delete)\s*\(\s*\$(?:request|url|endpoint|target)/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('HIGH', $relativePath,
                    "[A10] SSRF: Petición HTTP con URL del usuario. Valida que la URL pertenece a dominios permitidos.");
            }

            if (preg_match_all('/(?:file_get_contents|curl_setopt|Guzzle|Http::)\s*.*\$(?:url|request->url|request->get\(["\']url)/', $content, $m)) {
                $findings += count($m[0]);
                $this->addIssue('HIGH', $relativePath,
                    "[A10] SSRF potencial: recuperación de recurso remoto con URL del usuario sin validación.");
            }
        }

        $score = max(0, 100 - ($findings * 20));
        $this->addOwaspResult('A10', 'Server-Side Request Forgery (SSRF)', $score, $findings,
            'La aplicación hace peticiones HTTP a URLs controladas por el usuario sin validación.');
    }

    private function addOwaspResult(string $code, string $name, float $score, int $findings, string $description): void
    {
        $this->owaspResults[$code] = [
            'code'           => $code,
            'name'           => $name,
            'score'          => round($score, 1),
            'risk'           => $this->scoreToRisk($score),
            'findings_count' => $findings,
            'description'    => $description,
        ];
    }
}
