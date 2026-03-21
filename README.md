# 🔍 Laravel Best Practices Analyzer CLI

A command-line tool for analyzing the quality and security of Laravel projects.
Zero external dependencies — pure PHP only.

---

## 📦 Installation

### Option A — Direct use (no composer install required)
```bash
git clone https://github.com/your-user/laravel-analyzer
cd laravel-analyzer
chmod +x bin/laravel-analyze
php bin/laravel-analyze /path/to/your-laravel-project
```

### Option B — Global via Composer
```bash
composer global require laravel-analyzer/cli
laravel-analyze /path/to/your-project
```

---

## 🚀 Usage

```bash
# Analyze the current directory
laravel-analyze .

# Analyze a specific path
laravel-analyze /var/www/my-project

# Run only specific modules
laravel-analyze . --only=security,owasp

# Export HTML report
laravel-analyze . --format=html --output=report.html

# Export JSON for CI/CD
laravel-analyze . --format=json --output=analysis.json

# Export Markdown (for GitHub/GitLab)
laravel-analyze . --format=markdown --output=ANALYSIS.md

# Set a minimum quality threshold
laravel-analyze . --threshold=75

# Disable colors (for logs/CI)
laravel-analyze . --no-color
```

---

## 📊 Analysis Modules

### 🔗 Coupling & Cohesion (`coupling`)
Detects violations of the Single Responsibility Principle (SRP).

| Metric | Description |
|--------|-------------|
| Average coupling | Number of dependencies per class |
| God Classes | Classes with > 20 methods or > 500 lines |
| Long methods | Methods with > 50 lines |
| Estimated cohesion | How related the class responsibilities are |

**How to improve?**
- Break God Classes into specific services
- Use dependency injection instead of `new ClassName()`
- Define interfaces for each dependency

---

### 🧪 Test Coverage (`testing`)
Evaluates the quality and coverage of the test suite.

| Metric | Description |
|--------|-------------|
| Unit tests | Files in `tests/Unit/` |
| Feature tests | Files in `tests/Feature/` |
| Test/code ratio | % of source files with associated tests |
| Line coverage | From `clover.xml` (if present) |

**To generate a coverage report:**
```bash
php artisan test --coverage-clover=coverage.xml
laravel-analyze .  # Detects coverage.xml automatically
```

---

### 💸 Technical Debt (`debt`)
Identifies indicators of accumulated technical debt.

| Indicator | Severity |
|-----------|----------|
| `FIXME` | HIGH |
| `HACK` / `XXX` | MEDIUM |
| `TODO` | LOW |
| `$guarded = []` | CRITICAL |
| `Model::create($request->all())` | CRITICAL |
| Dependencies with wildcard version `*` | HIGH |
| Large commented-out code blocks | MEDIUM |

---

### 🧮 Refactoring Complexity (`complexity`)
Analyzes the Cyclomatic Complexity (CC) of each method.

| CC | Risk | Description |
|----|------|-------------|
| 1–5 | Low | Simple, easy to test |
| 6–10 | Medium | Moderate, testable |
| 11–20 | High | Hard to test |
| > 20 | Critical | Practically untestable |

Formula: `CC = 1 + (if + for + foreach + while + case + catch + && + \|\|)`

---

### 🔒 Laravel Security (`security`)
Detects vulnerabilities specific to the Laravel ecosystem.

| Vulnerability | OWASP | Dangerous example |
|---------------|-------|-------------------|
| SQL Injection | A03 | `DB::select("SELECT * WHERE id=" . $id)` |
| Mass Assignment | A01 | `Model::create($request->all())` |
| XSS | A03 | `{!! $userInput !!}` |
| Command Injection | A03 | `shell_exec("ls " . $path)` |
| Weak Hashing | A02 | `md5($password)` |
| Open Redirect | A01 | `redirect($request->get('url'))` |
| Debug in prod | A05 | `APP_DEBUG=true` + `APP_ENV=production` |

---

### 🛡️ OWASP Top 10 (`owasp`)
Checks the project against the OWASP Top 10 standard (2021).

| Code | Category | What it checks |
|------|----------|----------------|
| A01 | Broken Access Control | Policies, IDOR, protected routes |
| A02 | Cryptographic Failures | MD5/SHA1, hardcoded secrets, HTTPS |
| A03 | Injection | SQL, Command, Object injection |
| A04 | Insecure Design | Rate limiting, validation on store/update |
| A05 | Security Misconfiguration | APP_DEBUG, SameSite cookies, CORS |
| A06 | Vulnerable Components | Dependency versions, composer.lock |
| A07 | Auth Failures | Session fixation, MFA, regeneration |
| A08 | Integrity Failures | CI/CD, unserialize(), secure pipelines |
| A09 | Logging Failures | Security events logged |
| A10 | SSRF | HTTP requests with user-supplied URLs |

---

## 📋 Output Formats

### Console (default)
Colorized terminal view with progress bars.

### JSON
```json
{
  "generated_at": "2025-03-21 10:00:00",
  "project": "my-laravel-app",
  "global_score": 72.5,
  "grade": "B",
  "analyses": {
    "coupling": { "score": 78.2, "risk": "MEDIUM", ... },
    "owasp": { "score": 65.0, "risk": "MEDIUM", ... }
  }
}
```

### HTML
Full visual report with tables, progress bars, and OWASP breakdown.

### Markdown
Compatible with GitHub/GitLab. Ideal for PRs or documentation wikis.

---

## 🔄 CI/CD Integration

### GitHub Actions
```yaml
name: Laravel Quality Check
on: [push, pull_request]

jobs:
  analyze:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Run Laravel Analyzer
        run: php bin/laravel-analyze . --format=json --output=analysis.json --no-color

      - name: Check quality threshold
        run: |
          SCORE=$(python3 -c "import json; d=json.load(open('analysis.json')); print(d['global_score'])")
          if python3 -c "exit(0 if $SCORE >= 60 else 1)"; then
            echo "Quality score: $SCORE/100 — OK"
          else
            echo "Quality score ($SCORE) below threshold (60)"; exit 1
          fi

      - name: Upload report
        uses: actions/upload-artifact@v3
        with:
          name: laravel-analysis
          path: analysis.json
```

### GitLab CI
```yaml
laravel-analysis:
  stage: test
  script:
    - php bin/laravel-analyze . --format=json --output=analysis.json --no-color --threshold=65
  artifacts:
    paths:
      - analysis.json
```

---

## 🛠️ Complementary Tools

| Tool | Installation | Purpose |
|------|-------------|---------|
| **Larastan/PHPStan** | `composer require --dev nunomaduro/larastan` | Advanced static analysis |
| **Laravel Pint** | Included in Laravel 9+ | Code formatting |
| **Enlightn** | `composer require --dev enlightn/enlightn` | Security audit |
| **PHP Insights** | `composer require nunomaduro/phpinsights` | Quality metrics |
| **PHPMD** | `composer require --dev phpmd/phpmd` | Code smell detection |

---

## 📈 Score Interpretation

| Score | Grade | Meaning |
|-------|-------|---------|
| 90–100 | A+ | Excellent quality |
| 80–89 | A | Very good quality |
| 70–79 | B | Good quality, minor improvements needed |
| 60–69 | C | Acceptable quality, work needed |
| 50–59 | D | Low quality, urgent refactoring required |
| < 50 | F | Critical quality, high risk |

---

## 📝 License

MIT License — Free for commercial and personal use.
