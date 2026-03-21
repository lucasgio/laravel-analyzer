<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analiza riesgos de seguridad específicos de Laravel.
 *
 * Detecta: SQL Injection, Mass Assignment, XSS, CSRF bypass,
 * exposición de datos sensibles, configuraciones inseguras.
 */
class SecurityAnalyzer extends BaseAnalyzer
{
    private const SECURITY_RULES = [
        // SQL Injection risks
        'sql_injection' => [
            '/DB::(?:select|insert|update|delete|statement)\s*\(\s*["\'][^"\']*\.\s*\$/' => [
                'label'    => 'Posible SQL Injection',
                'desc'     => 'Concatenación de variable en query SQL cruda. Usa bindings: DB::select("query", [$variable])',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
            '/->whereRaw\s*\(\s*["\'][^"\']*\.\s*\$/' => [
                'label'    => 'SQL Injection en whereRaw()',
                'desc'     => 'Concatenación directa en whereRaw(). Usa bindings: ->whereRaw("col = ?", [$value])',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
            '/->(?:selectRaw|havingRaw|groupByRaw|orderByRaw)\s*\(\s*.*\.\s*\$/' => [
                'label'    => 'SQL Injection en método Raw',
                'desc'     => 'Variable concatenada en método *Raw(). Usa parámetros vinculados.',
                'severity' => 'HIGH',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Mass Assignment
        'mass_assignment' => [
            '/\$fillable\s*=\s*\[\s*\]/' => [
                'label'    => 'Mass Assignment: \$fillable vacío',
                'desc'     => '\$fillable vacío en el modelo. Define explícitamente los campos permitidos.',
                'severity' => 'HIGH',
                'owasp'    => 'A01:2021',
            ],
            '/protected\s+\$guarded\s*=\s*\[\s*\]/' => [
                'label'    => 'Mass Assignment: \$guarded vacío',
                'desc'     => '\$guarded = [] desactiva todas las protecciones de mass assignment. Muy peligroso.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A01:2021',
            ],
            '/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => [
                'label'    => 'Mass Assignment con \$request->all()',
                'desc'     => 'Usar fill(\$request->all()) puede asignar campos no deseados. Usa only() o validated().',
                'severity' => 'HIGH',
                'owasp'    => 'A01:2021',
            ],
            '/::create\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => [
                'label'    => 'Mass Assignment: Model::create(\$request->all())',
                'desc'     => 'Model::create(\$request->all()) es peligroso. Usa \$request->validated() o \$request->only([...]).',
                'severity' => 'CRITICAL',
                'owasp'    => 'A01:2021',
            ],
        ],

        // XSS
        'xss' => [
            '/\{!!\s*\$(?!cspNonce)/' => [
                'label'    => 'XSS: Blade unescaped output',
                'desc'     => '{!! !!} renderiza HTML sin escapar. Solo úsalo con datos de confianza. Para datos del usuario usa {{ }}.',
                'severity' => 'HIGH',
                'owasp'    => 'A03:2021',
            ],
            '/echo\s+\$request->/' => [
                'label'    => 'XSS: echo de input del usuario',
                'desc'     => 'echo directo de input del usuario sin escapar. Usa htmlspecialchars() o las plantillas Blade.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Authentication & Authorization
        'auth' => [
            '/Route::(?:get|post|put|patch|delete)\s*\([^)]+\)\s*(?:->name\([^)]+\))?(?:->middleware\([^)]*\))?\s*;(?!\s*\/\/)/' => [
                'label'    => 'Ruta sin middleware de autenticación',
                'desc'     => 'Verifica que las rutas sensibles tengan ->middleware("auth") o estén dentro de grupos protegidos.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A01:2021',
            ],
            '/\bauth\(\)\s*->\s*user\s*\(\)\s*->(?!id\b|name\b|email\b)/' => [
                'label'    => 'Acceso a propiedades de usuario sin verificación',
                'desc'     => 'Accede a propiedades del usuario autenticado después de verificar que no es null.',
                'severity' => 'LOW',
                'owasp'    => 'A07:2021',
            ],
        ],

        // Sensitive data exposure
        'data_exposure' => [
            '/\bdd\s*\(/' => [
                'label'    => 'dd() en código de producción',
                'desc'     => 'dd() expone información interna. Elimínalo antes de hacer deploy.',
                'severity' => 'HIGH',
                'owasp'    => 'A05:2021',
            ],
            '/\bvar_dump\s*\(/' => [
                'label'    => 'var_dump() en código de producción',
                'desc'     => 'var_dump() puede exponer datos sensibles. Usa el logger de Laravel.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A05:2021',
            ],
            '/\bprint_r\s*\(/' => [
                'label'    => 'print_r() en código de producción',
                'desc'     => 'print_r() puede exponer datos sensibles. Usa Log::debug() para debugging.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A05:2021',
            ],
            '/->password\b/' => [
                'label'    => 'Acceso directo a campo password',
                'desc'     => 'Verifica que los campos password estén ocultos en \$hidden del modelo y nunca se expongan en respuestas JSON.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A02:2021',
            ],
        ],

        // Command Injection
        'command_injection' => [
            '/\b(?:exec|shell_exec|system|passthru|popen)\s*\(\s*.*\$/' => [
                'label'    => 'Command Injection: ejecución de comandos con variable',
                'desc'     => 'Ejecución de comandos del sistema con datos potencialmente no sanitizados. Extremadamente peligroso.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
            '/\beval\s*\(/' => [
                'label'    => 'Uso de eval()',
                'desc'     => 'eval() ejecuta código PHP arbitrario. Nunca usarlo con datos del usuario.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Cryptographic failures
        'crypto' => [
            '/\bmd5\s*\(/' => [
                'label'    => 'Uso de MD5 (hash débil)',
                'desc'     => 'MD5 no es seguro para hashing de contraseñas. Usa bcrypt() o Hash::make().',
                'severity' => 'HIGH',
                'owasp'    => 'A02:2021',
            ],
            '/\bsha1\s*\(/' => [
                'label'    => 'Uso de SHA1 (hash débil)',
                'desc'     => 'SHA1 es inseguro para datos sensibles. Usa Hash::make() con bcrypt para contraseñas.',
                'severity' => 'HIGH',
                'owasp'    => 'A02:2021',
            ],
            '/password_hash\s*\([^,]+,\s*PASSWORD_DEFAULT\s*,\s*\[\s*["\'"]cost["\'\"]\s*=>\s*[1-9]\s*\]\s*\)/' => [
                'label'    => 'Bcrypt con bajo factor de coste',
                'desc'     => 'Factor de coste bcrypt muy bajo. El mínimo recomendado es 12.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A02:2021',
            ],
        ],

        // File inclusion
        'file_inclusion' => [
            '/\b(?:include|require)(?:_once)?\s*\(\s*\$/' => [
                'label'    => 'File Inclusion con variable',
                'desc'     => 'Include/require con variable puede llevar a Remote/Local File Inclusion. Valida y sanitiza la ruta.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Open redirect
        'redirect' => [
            '/return\s+redirect\(\s*\$request->(?:get|input|query)/' => [
                'label'    => 'Open Redirect',
                'desc'     => 'Redirect con URL del usuario puede llevar a Open Redirect. Valida que la URL sea interna.',
                'severity' => 'HIGH',
                'owasp'    => 'A01:2021',
            ],
        ],
    ];

    public function analyze(string $projectPath): array
    {
        $this->projectPath = $projectPath;
        $this->issues = [];
        $this->recommendations = [];

        $files = $this->getPhpFiles($projectPath);

        $vulnerabilities     = [];
        $criticalCount       = 0;
        $highCount           = 0;
        $mediumCount         = 0;
        $categoryCounts      = [];

        foreach ($files as $file) {
            $content      = $this->readFile($file);
            $relativePath = str_replace($projectPath . '/', '', $file);

            foreach (self::SECURITY_RULES as $category => $rules) {
                foreach ($rules as $pattern => $info) {
                    if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                        foreach ($matches[0] as $match) {
                            $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;

                            $vuln = [
                                'category' => $category,
                                'label'    => $info['label'],
                                'file'     => $relativePath,
                                'line'     => $line,
                                'severity' => $info['severity'],
                                'desc'     => $info['desc'],
                                'owasp'    => $info['owasp'],
                            ];

                            $vulnerabilities[] = $vuln;
                            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

                            match($info['severity']) {
                                'CRITICAL' => $criticalCount++,
                                'HIGH'     => $highCount++,
                                'MEDIUM'   => $mediumCount++,
                                default    => null,
                            };

                            $this->addIssue(
                                $info['severity'],
                                "{$relativePath}:{$line}",
                                "[{$info['owasp']}] {$info['label']}: {$info['desc']}"
                            );
                        }
                    }
                }
            }
        }

        // Check Laravel security config
        $configIssues = $this->checkSecurityConfiguration($projectPath);

        // Score
        $score = $this->calculateSecurityScore($criticalCount, $highCount, $mediumCount, count($vulnerabilities));

        $this->generateSecurityRecommendations($vulnerabilities, $configIssues);

        return $this->buildResult($score, [
            'total_vulnerabilities' => count($vulnerabilities),
            'critical'              => $criticalCount,
            'high'                  => $highCount,
            'medium'                => $mediumCount,
            'by_category'           => $categoryCounts,
            'config_issues'         => $configIssues,
            'top_vulnerabilities'   => array_slice(
                array_filter($vulnerabilities, fn($v) => $v['severity'] === 'CRITICAL' || $v['severity'] === 'HIGH'),
                0, 10
            ),
        ], "Vulnerabilidades: {$criticalCount} críticas, {$highCount} altas, {$mediumCount} medias. " .
           "Total: " . count($vulnerabilities) . " problemas.");
    }

    private function checkSecurityConfiguration(string $projectPath): array
    {
        $issues = [];

        // Check .env
        $envFile = $projectPath . '/.env';
        if (file_exists($envFile)) {
            $env = $this->readFile($envFile);

            if (preg_match('/APP_DEBUG\s*=\s*true/i', $env) &&
                preg_match('/APP_ENV\s*=\s*production/i', $env)) {
                $issues[] = ['severity' => 'CRITICAL', 'msg' => 'APP_DEBUG=true en producción expone stacktraces y variables de entorno.'];
            }

            if (!preg_match('/SESSION_SECURE_COOKIE\s*=\s*true/i', $env)) {
                $issues[] = ['severity' => 'MEDIUM', 'msg' => 'SESSION_SECURE_COOKIE no está en true. Las cookies de sesión deberían ser HTTPS-only.'];
            }
        }

        // Check cors config
        $corsConfig = $projectPath . '/config/cors.php';
        if (file_exists($corsConfig)) {
            $content = $this->readFile($corsConfig);
            if (str_contains($content, "'*'") && str_contains($content, 'allowed_origins')) {
                $issues[] = ['severity' => 'HIGH', 'msg' => "CORS configurado con wildcard ('*'). Limita los orígenes permitidos."];
            }
        }

        // Check sanctum/passport
        $composerFile = $projectPath . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode($this->readFile($composerFile), true);
            $hasAuth = isset($composer['require']['laravel/sanctum']) ||
                       isset($composer['require']['laravel/passport']);
            if (!$hasAuth) {
                $issues[] = ['severity' => 'MEDIUM', 'msg' => 'Sin paquete de autenticación API (Sanctum/Passport). Si tienes API, implementa autenticación.'];
            }
        }

        return $issues;
    }

    private function calculateSecurityScore(int $critical, int $high, int $medium, int $total): float
    {
        $score = 100;
        $score -= $critical * 15;
        $score -= $high * 7;
        $score -= $medium * 3;
        return max(0, min(100, $score));
    }

    private function generateSecurityRecommendations(array $vulns, array $configIssues): void
    {
        $categories = array_unique(array_column($vulns, 'category'));

        if (in_array('sql_injection', $categories)) {
            $this->addRecommendation("SQL Injection: Usa SIEMPRE el Query Builder de Laravel con bindings o Eloquent. Nunca concatenes variables en queries SQL.");
        }

        if (in_array('mass_assignment', $categories)) {
            $this->addRecommendation("Mass Assignment: Define \$fillable explícitamente en cada modelo. Usa \$request->validated() en lugar de \$request->all().");
        }

        if (in_array('xss', $categories)) {
            $this->addRecommendation("XSS: Usa {{ }} de Blade (auto-escapa). Evita {!! !!} excepto para HTML de confianza. Implementa Content Security Policy (CSP).");
        }

        if (in_array('crypto', $categories)) {
            $this->addRecommendation("Criptografía: Usa Hash::make() (bcrypt por defecto) para contraseñas. Nunca MD5 o SHA1. Para tokens, usa Str::random() o bin2hex(random_bytes(32)).");
        }

        $this->addRecommendation("Ejecuta 'php artisan audit' con laravel-security-checker: 'composer require enlightn/enlightn --dev && php artisan enlightn'.");
        $this->addRecommendation("Configura Content Security Policy headers usando spatie/laravel-csp: 'composer require spatie/laravel-csp'.");
        $this->addRecommendation("Revisa las recomendaciones de seguridad de Laravel: https://laravel.com/docs/security");
        $this->addRecommendation("Ejecuta 'composer audit' regularmente para detectar vulnerabilidades en dependencias.");
    }
}
