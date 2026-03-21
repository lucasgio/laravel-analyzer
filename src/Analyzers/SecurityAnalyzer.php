<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

/**
 * Analyzes Laravel-specific security risks.
 *
 * Detects: SQL Injection, Mass Assignment, XSS, CSRF bypass,
 * sensitive data exposure, and insecure configurations.
 */
class SecurityAnalyzer extends BaseAnalyzer
{
    private const SECURITY_RULES = [
        // SQL Injection risks
        'sql_injection' => [
            '/DB::(?:select|insert|update|delete|statement)\s*\(\s*["\'][^"\']*\.\s*\$/' => [
                'label'    => 'Possible SQL Injection',
                'desc'     => 'Variable concatenated into a raw SQL query. Use bindings: DB::select("query", [$variable])',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
            '/->whereRaw\s*\(\s*["\'][^"\']*\.\s*\$/' => [
                'label'    => 'SQL Injection in whereRaw()',
                'desc'     => 'Direct concatenation in whereRaw(). Use bindings: ->whereRaw("col = ?", [$value])',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
            '/->(?:selectRaw|havingRaw|groupByRaw|orderByRaw)\s*\(\s*.*\.\s*\$/' => [
                'label'    => 'SQL Injection in Raw method',
                'desc'     => 'Variable concatenated in a *Raw() method. Use bound parameters.',
                'severity' => 'HIGH',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Mass Assignment
        'mass_assignment' => [
            '/\$fillable\s*=\s*\[\s*\]/' => [
                'label'    => 'Mass Assignment: empty \$fillable',
                'desc'     => 'Empty \$fillable in model. Explicitly define the allowed fields.',
                'severity' => 'HIGH',
                'owasp'    => 'A01:2021',
            ],
            '/protected\s+\$guarded\s*=\s*\[\s*\]/' => [
                'label'    => 'Mass Assignment: empty \$guarded',
                'desc'     => '\$guarded = [] disables all mass assignment protections. Very dangerous.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A01:2021',
            ],
            '/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => [
                'label'    => 'Mass Assignment with \$request->all()',
                'desc'     => 'Using fill(\$request->all()) can assign unintended fields. Use only() or validated() instead.',
                'severity' => 'HIGH',
                'owasp'    => 'A01:2021',
            ],
            '/::create\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => [
                'label'    => 'Mass Assignment: Model::create(\$request->all())',
                'desc'     => 'Model::create(\$request->all()) is dangerous. Use \$request->validated() or \$request->only([...]).',
                'severity' => 'CRITICAL',
                'owasp'    => 'A01:2021',
            ],
        ],

        // XSS
        'xss' => [
            '/\{!!\s*\$(?!cspNonce)/' => [
                'label'    => 'XSS: Blade unescaped output',
                'desc'     => '{!! !!} renders HTML without escaping. Only use with trusted data. For user input use {{ }}.',
                'severity' => 'HIGH',
                'owasp'    => 'A03:2021',
            ],
            '/echo\s+\$request->/' => [
                'label'    => 'XSS: echo of user input',
                'desc'     => 'Direct echo of user input without escaping. Use htmlspecialchars() or Blade templates.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Authentication & Authorization
        'auth' => [
            '/Route::(?:get|post|put|patch|delete)\s*\([^)]+\)\s*(?:->name\([^)]+\))?(?:->middleware\([^)]*\))?\s*;(?!\s*\/\/)/' => [
                'label'    => 'Route without authentication middleware',
                'desc'     => 'Verify that sensitive routes have ->middleware("auth") or are inside protected groups.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A01:2021',
            ],
            '/\bauth\(\)\s*->\s*user\s*\(\)\s*->(?!id\b|name\b|email\b)/' => [
                'label'    => 'Accessing user properties without null check',
                'desc'     => 'Access authenticated user properties only after verifying the user is not null.',
                'severity' => 'LOW',
                'owasp'    => 'A07:2021',
            ],
        ],

        // Sensitive data exposure
        'data_exposure' => [
            '/\bdd\s*\(/' => [
                'label'    => 'dd() in production code',
                'desc'     => 'dd() exposes internal information. Remove it before deploying.',
                'severity' => 'HIGH',
                'owasp'    => 'A05:2021',
            ],
            '/\bvar_dump\s*\(/' => [
                'label'    => 'var_dump() in production code',
                'desc'     => 'var_dump() can expose sensitive data. Use Laravel\'s logger instead.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A05:2021',
            ],
            '/\bprint_r\s*\(/' => [
                'label'    => 'print_r() in production code',
                'desc'     => 'print_r() can expose sensitive data. Use Log::debug() for debugging.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A05:2021',
            ],
            '/->password\b/' => [
                'label'    => 'Direct access to password field',
                'desc'     => 'Ensure password fields are listed in \$hidden on the model and never exposed in JSON responses.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A02:2021',
            ],
        ],

        // Command Injection
        'command_injection' => [
            '/\b(?:exec|shell_exec|system|passthru|popen)\s*\(\s*.*\$/' => [
                'label'    => 'Command Injection: system command with variable',
                'desc'     => 'Executing system commands with potentially unsanitized data. Extremely dangerous.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
            '/\beval\s*\(/' => [
                'label'    => 'Use of eval()',
                'desc'     => 'eval() runs arbitrary PHP code. Never use it with user-supplied data.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Cryptographic failures
        'crypto' => [
            '/\bmd5\s*\(/' => [
                'label'    => 'Use of MD5 (weak hash)',
                'desc'     => 'MD5 is not secure for password hashing. Use bcrypt() or Hash::make().',
                'severity' => 'HIGH',
                'owasp'    => 'A02:2021',
            ],
            '/\bsha1\s*\(/' => [
                'label'    => 'Use of SHA1 (weak hash)',
                'desc'     => 'SHA1 is insecure for sensitive data. Use Hash::make() with bcrypt for passwords.',
                'severity' => 'HIGH',
                'owasp'    => 'A02:2021',
            ],
            '/password_hash\s*\([^,]+,\s*PASSWORD_DEFAULT\s*,\s*\[\s*["\'"]cost["\'\"]\s*=>\s*[1-9]\s*\]\s*\)/' => [
                'label'    => 'Bcrypt with low cost factor',
                'desc'     => 'Bcrypt cost factor is too low. Minimum recommended value is 12.',
                'severity' => 'MEDIUM',
                'owasp'    => 'A02:2021',
            ],
        ],

        // File inclusion
        'file_inclusion' => [
            '/\b(?:include|require)(?:_once)?\s*\(\s*\$/' => [
                'label'    => 'File Inclusion with variable',
                'desc'     => 'include/require with a variable can lead to Remote/Local File Inclusion. Validate and sanitize the path.',
                'severity' => 'CRITICAL',
                'owasp'    => 'A03:2021',
            ],
        ],

        // Open redirect
        'redirect' => [
            '/return\s+redirect\(\s*\$request->(?:get|input|query)/' => [
                'label'    => 'Open Redirect',
                'desc'     => 'Redirecting to a user-supplied URL can lead to Open Redirect attacks. Validate that the URL is internal.',
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
        ], "Vulnerabilities: {$criticalCount} critical, {$highCount} high, {$mediumCount} medium. " .
           "Total: " . count($vulnerabilities) . " issues.");
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
                $issues[] = ['severity' => 'CRITICAL', 'msg' => 'APP_DEBUG=true in production exposes stack traces and environment variables.'];
            }

            if (!preg_match('/SESSION_SECURE_COOKIE\s*=\s*true/i', $env)) {
                $issues[] = ['severity' => 'MEDIUM', 'msg' => 'SESSION_SECURE_COOKIE is not set to true. Session cookies should be HTTPS-only.'];
            }
        }

        // Check cors config
        $corsConfig = $projectPath . '/config/cors.php';
        if (file_exists($corsConfig)) {
            $content = $this->readFile($corsConfig);
            if (str_contains($content, "'*'") && str_contains($content, 'allowed_origins')) {
                $issues[] = ['severity' => 'HIGH', 'msg' => "CORS configured with wildcard ('*'). Restrict to specific allowed origins."];
            }
        }

        // Check sanctum/passport
        $composerFile = $projectPath . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode($this->readFile($composerFile), true);
            $hasAuth = isset($composer['require']['laravel/sanctum']) ||
                       isset($composer['require']['laravel/passport']);
            if (!$hasAuth) {
                $issues[] = ['severity' => 'MEDIUM', 'msg' => 'No API authentication package found (Sanctum/Passport). If you have an API, implement authentication.'];
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
            $this->addRecommendation("SQL Injection: ALWAYS use Laravel's Query Builder with bindings or Eloquent. Never concatenate variables into SQL queries.");
        }

        if (in_array('mass_assignment', $categories)) {
            $this->addRecommendation("Mass Assignment: Explicitly define \$fillable on every model. Use \$request->validated() instead of \$request->all().");
        }

        if (in_array('xss', $categories)) {
            $this->addRecommendation("XSS: Use Blade's {{ }} (auto-escapes). Avoid {!! !!} except for trusted HTML. Implement Content Security Policy (CSP).");
        }

        if (in_array('crypto', $categories)) {
            $this->addRecommendation("Cryptography: Use Hash::make() (bcrypt by default) for passwords. Never MD5 or SHA1. For tokens use Str::random() or bin2hex(random_bytes(32)).");
        }

        $this->addRecommendation("Run a security audit with Enlightn: 'composer require enlightn/enlightn --dev && php artisan enlightn'.");
        $this->addRecommendation("Configure Content Security Policy headers with spatie/laravel-csp: 'composer require spatie/laravel-csp'.");
        $this->addRecommendation("Review Laravel's security documentation: https://laravel.com/docs/security");
        $this->addRecommendation("Run 'composer audit' regularly to detect vulnerabilities in dependencies.");
    }
}
