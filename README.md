# 🔍 Laravel Best Practices Analyzer CLI

Herramienta de línea de comandos para analizar la calidad y seguridad de proyectos Laravel.  
Sin dependencias externas — funciona con PHP puro.

---

## 📦 Instalación

### Opción A — Uso directo (sin composer install)
```bash
git clone https://github.com/tu-usuario/laravel-analyzer
cd laravel-analyzer
chmod +x bin/laravel-analyze
php bin/laravel-analyze /ruta/tu-proyecto-laravel
```

### Opción B — Global vía Composer
```bash
composer global require laravel-analyzer/cli
laravel-analyze /ruta/tu-proyecto
```

---

## 🚀 Uso

```bash
# Analizar el directorio actual
laravel-analyze .

# Analizar una ruta específica
laravel-analyze /var/www/mi-proyecto

# Solo ciertos módulos
laravel-analyze . --only=security,owasp

# Exportar reporte HTML
laravel-analyze . --format=html --output=reporte.html

# Exportar JSON para CI/CD
laravel-analyze . --format=json --output=analysis.json

# Exportar Markdown (para GitHub/GitLab)
laravel-analyze . --format=markdown --output=ANALYSIS.md

# Definir umbral mínimo de calidad
laravel-analyze . --threshold=75

# Sin colores (para logs/CI)
laravel-analyze . --no-color
```

---

## 📊 Módulos de Análisis

### 🔗 Acoplamiento & Cohesión (`coupling`)
Detecta violaciones al principio de responsabilidad única (SRP).

| Métrica | Descripción |
|---------|-------------|
| Acoplamiento promedio | Número de dependencias por clase |
| God Classes | Clases con > 20 métodos o > 500 líneas |
| Métodos largos | Métodos con > 50 líneas |
| Cohesión estimada | Cuán relacionadas están las responsabilidades |

**¿Cómo mejorar?**
- Divide las God Classes en servicios específicos
- Usa inyección de dependencias en lugar de `new ClassName()`
- Define interfaces para cada dependencia

---

### 🧪 Cobertura de Tests (`testing`)
Evalúa la calidad y cobertura del suite de tests.

| Métrica | Descripción |
|---------|-------------|
| Tests unitarios | Archivos en `tests/Unit/` |
| Tests de feature | Archivos en `tests/Feature/` |
| Ratio tests/código | % de archivos de código con tests |
| Cobertura de líneas | Desde `clover.xml` (si existe) |

**Para generar reporte de cobertura:**
```bash
php artisan test --coverage-clover=coverage.xml
laravel-analyze . # Detecta coverage.xml automáticamente
```

---

### 💸 Deuda Técnica (`debt`)
Identifica indicadores de deuda técnica acumulada.

| Indicador | Severidad |
|-----------|-----------|
| `FIXME` | ALTA |
| `HACK` / `XXX` | MEDIA |
| `TODO` | BAJA |
| `$guarded = []` | CRÍTICA |
| `Model::create($request->all())` | CRÍTICA |
| Dependencias con versión `*` | ALTA |
| Código comentado en bloques | MEDIA |

---

### 🧮 Complejidad de Refactorización (`complexity`)
Analiza la Complejidad Ciclomática (CC) de cada método.

| CC | Riesgo | Descripción |
|----|--------|-------------|
| 1–5 | Bajo | Simple, fácil de testear |
| 6–10 | Medio | Moderado, testeable |
| 11–20 | Alto | Difícil de testear |
| > 20 | Crítico | Prácticamente no testeable |

Fórmula: `CC = 1 + (if + for + foreach + while + case + catch + && + \|\|)`

---

### 🔒 Seguridad Laravel (`security`)
Detecta vulnerabilidades específicas del ecosistema Laravel.

| Vulnerabilidad | OWASP | Ejemplo peligroso |
|----------------|-------|-------------------|
| SQL Injection | A03 | `DB::select("SELECT * WHERE id=" . $id)` |
| Mass Assignment | A01 | `Model::create($request->all())` |
| XSS | A03 | `{!! $userInput !!}` |
| Command Injection | A03 | `exec("ls " . $path)` |
| Weak Hashing | A02 | `md5($password)` |
| Open Redirect | A01 | `redirect($request->get('url'))` |
| Debug en prod | A05 | `APP_DEBUG=true` + `APP_ENV=production` |

---

### 🛡️ OWASP Top 10 (`owasp`)
Verifica el proyecto contra el estándar OWASP Top 10 (2021).

| Código | Categoría | Qué verifica |
|--------|-----------|--------------|
| A01 | Broken Access Control | Policies, IDOR, rutas protegidas |
| A02 | Cryptographic Failures | MD5/SHA1, secretos hardcodeados, HTTPS |
| A03 | Injection | SQL, Command, Object injection |
| A04 | Insecure Design | Rate limiting, validación en store/update |
| A05 | Security Misconfiguration | APP_DEBUG, SameSite cookies, CORS |
| A06 | Vulnerable Components | Versiones de dependencias, composer.lock |
| A07 | Auth Failures | Session fixation, MFA, regeneración |
| A08 | Integrity Failures | CI/CD, unserialize(), pipelines seguros |
| A09 | Logging Failures | Eventos de seguridad loggeados |
| A10 | SSRF | Peticiones HTTP con URL del usuario |

---

## 📋 Formatos de Salida

### Console (default)
Vista colorida en terminal con barras de progreso.

### JSON
```json
{
  "generated_at": "2025-03-21 10:00:00",
  "project": "my-laravel-app",
  "global_score": 72.5,
  "grade": "B",
  "analyses": {
    "coupling": { "score": 78.2, "risk": "MEDIO", ... },
    "owasp": { "score": 65.0, "risk": "MEDIO", ... }
  }
}
```

### HTML
Reporte visual completo con tablas, barras de progreso y breakdown de OWASP.

### Markdown
Compatible con GitHub/GitLab. Ideal para PRs o wikis de documentación.

---

## 🔄 Integración CI/CD

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
        run: |
          php bin/laravel-analyze . --format=json --output=analysis.json --no-color
      
      - name: Check quality threshold
        run: |
          SCORE=$(cat analysis.json | python3 -c "import sys,json; print(json.load(sys.stdin)['global_score'])")
          if (( $(echo "$SCORE < 60" | bc -l) )); then
            echo "❌ Quality score ($SCORE) below threshold (60)"
            exit 1
          fi
          echo "✅ Quality score: $SCORE/100"
      
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
    reports:
      junit: analysis.json
```

---

## 🛠️ Herramientas Complementarias

| Herramienta | Instalación | Propósito |
|-------------|-------------|-----------|
| **Larastan/PHPStan** | `composer require --dev nunomaduro/larastan` | Análisis estático avanzado |
| **Laravel Pint** | Incluido en Laravel 9+ | Formateo de código |
| **Enlightn** | `composer require --dev enlightn/enlightn` | Auditoría seguridad |
| **PHP Insights** | `composer require nunomaduro/phpinsights` | Métricas de calidad |
| **PHPMD** | `composer require --dev phpmd/phpmd` | Detección de malos olores |

---

## 📈 Interpretación de Scores

| Score | Grado | Significado |
|-------|-------|-------------|
| 90–100 | A+ | Excelente calidad |
| 80–89 | A | Muy buena calidad |
| 70–79 | B | Buena calidad, mejoras menores |
| 60–69 | C | Calidad aceptable, trabajo necesario |
| 50–59 | D | Calidad baja, refactorización urgente |
| < 50 | F | Calidad crítica, riesgo alto |

---

## 📝 Licencia

MIT License — Libre para uso comercial y personal.
