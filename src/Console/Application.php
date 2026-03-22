<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Console;

use LaravelAnalyzer\Analyzers\CouplingCohesionAnalyzer;
use LaravelAnalyzer\Analyzers\TestCoverageAnalyzer;
use LaravelAnalyzer\Analyzers\TechnicalDebtAnalyzer;
use LaravelAnalyzer\Analyzers\ComplexityAnalyzer;
use LaravelAnalyzer\Analyzers\SecurityAnalyzer;
use LaravelAnalyzer\Analyzers\OwaspAnalyzer;
use LaravelAnalyzer\Analyzers\RefactoringAnalyzer;
use LaravelAnalyzer\Reports\ReportGenerator;
use LaravelAnalyzer\Refactoring\RefactoringPlanGenerator;
use LaravelAnalyzer\Skills\SkillsInstaller;

class Application
{
    private const VERSION = '1.3.0';
    private const BANNER = <<<'BANNER'
    ╔══════════════════════════════════════════════════════════════╗
    ║          Laravel Best Practices Analyzer v%s                 ║
    ║       Coupling · Cohesion · Tests · Security · OWASP         ║
    ╚══════════════════════════════════════════════════════════════╝
    BANNER;

    private array $results = [];
    private string $projectPath = '';
    private array $options = [];

    public function run(array $argv): void
    {
        $this->printBanner();
        $this->parseArguments($argv);

        if (isset($this->options['help'])) {
            $this->printHelp();
            exit(0);
        }

        if (isset($this->options['version'])) {
            echo self::VERSION . PHP_EOL;
            exit(0);
        }

        if (isset($this->options['install_skills'])) {
            $this->runInstallSkills();
            exit(0);
        }

        $this->validateProjectPath();
        $this->runAnalysis();
        $this->generateReport();

        if (isset($this->options['refactoring_plan'])) {
            $this->generateRefactoringPlan();
        }
    }

    private function parseArguments(array $argv): void
    {
        $this->projectPath = getcwd();

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if ($arg === 'install-skills') {
                $this->options['install_skills'] = true;
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->options['help'] = true;
            } elseif ($arg === '--version' || $arg === '-v') {
                $this->options['version'] = true;
            } elseif ($arg === '--format' && isset($argv[$i + 1])) {
                $this->options['format'] = $argv[++$i];
            } elseif (str_starts_with($arg, '--format=')) {
                $this->options['format'] = substr($arg, 9);
            } elseif ($arg === '--output' && isset($argv[$i + 1])) {
                $this->options['output'] = $argv[++$i];
            } elseif (str_starts_with($arg, '--output=')) {
                $this->options['output'] = substr($arg, 9);
            } elseif ($arg === '--only' && isset($argv[$i + 1])) {
                $this->options['only'] = explode(',', $argv[++$i]);
            } elseif (str_starts_with($arg, '--only=')) {
                $this->options['only'] = explode(',', substr($arg, 7));
            } elseif ($arg === '--threshold' && isset($argv[$i + 1])) {
                $this->options['threshold'] = (int)$argv[++$i];
            } elseif (str_starts_with($arg, '--threshold=')) {
                $this->options['threshold'] = (int)substr($arg, 12);
            } elseif ($arg === '--no-color') {
                $this->options['no_color'] = true;
            } elseif ($arg === '--refactoring-plan' && isset($argv[$i + 1])) {
                $this->options['refactoring_plan'] = $argv[++$i];
            } elseif (str_starts_with($arg, '--refactoring-plan=')) {
                $this->options['refactoring_plan'] = substr($arg, 19);
            } elseif ($arg === '--skills-only' && isset($argv[$i + 1])) {
                $this->options['skills_only'] = explode(',', $argv[++$i]);
            } elseif (str_starts_with($arg, '--skills-only=')) {
                $this->options['skills_only'] = explode(',', substr($arg, 14));
            } elseif (!str_starts_with($arg, '--')) {
                $this->projectPath = is_dir($arg) ? realpath($arg) : $arg;
            }
        }

        // Default format
        $this->options['format'] = $this->options['format'] ?? 'console';
        $this->options['threshold'] = $this->options['threshold'] ?? 60;
    }

    private function validateProjectPath(): void
    {
        if (!is_dir($this->projectPath)) {
            $this->error("Directory '{$this->projectPath}' does not exist.");
            exit(1);
        }

        // Check if it's a Laravel project
        $isLaravel = file_exists($this->projectPath . '/artisan') ||
                     file_exists($this->projectPath . '/app/Http/Kernel.php') ||
                     (file_exists($this->projectPath . '/composer.json') && $this->isLaravelComposer());

        if (!$isLaravel) {
            $this->warning("⚠  No standard Laravel project detected. Continuing anyway...");
        } else {
            $this->success("✓ Laravel project detected at: {$this->projectPath}");
        }

        echo PHP_EOL;
    }

    private function isLaravelComposer(): bool
    {
        $composerFile = $this->projectPath . '/composer.json';
        if (!file_exists($composerFile)) return false;
        $composer = json_decode(file_get_contents($composerFile), true);
        return isset($composer['require']['laravel/framework']) ||
               isset($composer['require']['laravel/laravel']);
    }

    private function runAnalysis(): void
    {
        $analyzers = $this->getAnalyzers();
        $only = $this->options['only'] ?? null;

        echo $this->color("\n📊 STARTING ANALYSIS\n", 'cyan');
        echo str_repeat('─', 60) . PHP_EOL;

        foreach ($analyzers as $key => $analyzer) {
            if ($only && !in_array($key, $only)) {
                continue;
            }

            echo $this->color("  → Analyzing: ", 'yellow') . $analyzer['name'] . "...";
            flush();

            try {
                $result = $analyzer['instance']->analyze($this->projectPath);
                $this->results[$key] = $result;
                echo $this->color(" ✓\n", 'green');
            } catch (\Throwable $e) {
                echo $this->color(" ✗ ERROR: " . $e->getMessage() . "\n", 'red');
                $this->results[$key] = ['error' => $e->getMessage(), 'score' => 0];
            }
        }

        echo PHP_EOL;
    }

    private function getAnalyzers(): array
    {
        return [
            'coupling'    => ['name' => 'Coupling & Cohesion',         'instance' => new CouplingCohesionAnalyzer()],
            'testing'     => ['name' => 'Test Coverage',               'instance' => new TestCoverageAnalyzer()],
            'debt'        => ['name' => 'Technical Debt',              'instance' => new TechnicalDebtAnalyzer()],
            'complexity'  => ['name' => 'Refactoring Complexity',      'instance' => new ComplexityAnalyzer()],
            'security'    => ['name' => 'Laravel Security Risk',       'instance' => new SecurityAnalyzer()],
            'owasp'       => ['name' => 'OWASP Top 10',                'instance' => new OwaspAnalyzer()],
            'refactoring' => ['name' => 'Refactoring Opportunities',   'instance' => new RefactoringAnalyzer()],
        ];
    }

    private function generateReport(): void
    {
        $generator = new ReportGenerator($this->results, $this->options, $this->projectPath);

        switch ($this->options['format']) {
            case 'json':
                $output = $generator->toJson();
                $this->writeOutput($output, 'json');
                break;
            case 'html':
                $output = $generator->toHtml();
                $this->writeOutput($output, 'html');
                break;
            case 'markdown':
            case 'md':
                $output = $generator->toMarkdown();
                $this->writeOutput($output, 'md');
                break;
            default:
                $generator->toConsole($this->options['no_color'] ?? false);
                break;
        }

        $this->printSummary();
    }

    private function runInstallSkills(): void
    {
        $only = $this->options['skills_only'] ?? null;

        echo $this->color("\n🛠  INSTALLING SKILLS\n", 'cyan');
        echo str_repeat('─', 60) . PHP_EOL;

        $installer = new SkillsInstaller($this->projectPath);
        $result    = $installer->install($only);

        foreach ($result['log'] as $entry) {
            echo $this->color("  ✓ ", 'green') . $entry . PHP_EOL;
        }

        if (!empty($result['skipped'])) {
            foreach ($result['skipped'] as $label) {
                echo $this->color("  – Skipped: ", 'yellow') . $label . PHP_EOL;
            }
        }

        echo PHP_EOL;
        $count = count($result['installed']);
        $this->success("✓ {$count} skill(s) installed in: {$this->projectPath}");
        echo $this->color("  See AGENT_SETUP.md for usage instructions.\n", 'cyan');
        echo PHP_EOL;
    }

    private function generateRefactoringPlan(): void
    {
        $outputPath = $this->options['refactoring_plan'];
        $project    = basename($this->projectPath);

        echo $this->color("\n♻️  GENERATING REFACTORING PLAN\n", 'cyan');
        echo str_repeat('─', 60) . PHP_EOL;

        $generator = new RefactoringPlanGenerator();
        $content   = $generator->generate($this->results, $project);

        file_put_contents($outputPath, $content);
        $this->success("✓ Refactoring plan saved to: {$outputPath}");
        echo PHP_EOL;
    }

    private function writeOutput(string $content, string $ext): void
    {
        $outputPath = $this->options['output'] ?? "laravel-analysis-report.{$ext}";
        file_put_contents($outputPath, $content);
        $this->success("✓ Report saved to: {$outputPath}");
    }

    private function printSummary(): void
    {
        $scores = array_filter(array_column($this->results, 'score'));
        if (empty($scores)) return;

        $avg = array_sum($scores) / count($scores);
        $threshold = $this->options['threshold'];

        echo PHP_EOL . str_repeat('═', 60) . PHP_EOL;
        echo $this->color("  GLOBAL PROJECT SCORE: ", 'white');

        $scoreColor = $avg >= 80 ? 'green' : ($avg >= $threshold ? 'yellow' : 'red');
        echo $this->color(sprintf("%.1f/100", $avg), $scoreColor);

        $grade = $this->getGrade($avg);
        echo "  [{$grade}]" . PHP_EOL;
        echo str_repeat('═', 60) . PHP_EOL . PHP_EOL;

        if ($avg < $threshold) {
            $this->warning("⚠  Project is below the minimum quality threshold ({$threshold}/100)");
            echo PHP_EOL;
            exit(1);
        } else {
            $this->success("✓ Project meets the quality threshold ({$threshold}/100)");
        }
        echo PHP_EOL;
    }

    private function getGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            default      => 'F',
        };
    }

    private function printBanner(): void
    {
        $version = str_pad(self::VERSION, 5);
        echo $this->color(sprintf(self::BANNER, $version), 'cyan') . PHP_EOL;
    }

    private function printHelp(): void
    {
        echo <<<HELP

{$this->color('USAGE:', 'yellow')}
  laravel-analyze [project-path] [options]
  laravel-analyze install-skills [project-path] [--skills-only=SKILLS]

{$this->color('OPTIONS:', 'yellow')}
  -h, --help                    Show this help message
  -v, --version                 Show CLI version
  --format=FORMAT               Output format: console (default), json, html, markdown
  --output=FILE                 Output file path
  --only=MODULES                Run only specific modules (comma-separated)
  --threshold=N                 Minimum quality score threshold (default: 60)
  --no-color                    Disable colored output
  --refactoring-plan=FILE       Generate a phased refactoring plan after analysis

{$this->color('AVAILABLE MODULES:', 'yellow')}
  coupling    Class coupling and cohesion analysis
  testing     Test coverage (Unit, Feature, Integration)
  debt        Technical debt risk
  complexity  Cyclomatic complexity and refactoring risk
  security    Laravel-specific security risks
  owasp       OWASP Top 10 compliance check

{$this->color('INSTALL-SKILLS SUBCOMMAND:', 'yellow')}
  Installs Claude Code slash commands and Cursor rules into a Laravel project.
  --skills-only=SKILLS          Install only specific skills (comma-separated)

{$this->color('AVAILABLE SKILLS:', 'yellow')}
  laravel       Laravel General Best Practices
  laravel-api   Laravel API REST Best Practices
  react         React Best Practices
  vue-inertia   Vue.js + Inertia.js Best Practices

{$this->color('EXAMPLES:', 'yellow')}
  laravel-analyze /var/www/my-app
  laravel-analyze . --format=html --output=report.html
  laravel-analyze . --only=security,owasp
  laravel-analyze . --format=json --threshold=75
  laravel-analyze . --refactoring-plan=REFACTORING_PLAN.md
  laravel-analyze install-skills /var/www/my-app
  laravel-analyze install-skills . --skills-only=laravel,laravel-api

HELP;
    }

    private function color(string $text, string $color): string
    {
        if ($this->options['no_color'] ?? false) return $text;
        $colors = [
            'red'    => "\033[0;31m",
            'green'  => "\033[0;32m",
            'yellow' => "\033[1;33m",
            'cyan'   => "\033[0;36m",
            'white'  => "\033[1;37m",
            'reset'  => "\033[0m",
        ];
        return ($colors[$color] ?? '') . $text . $colors['reset'];
    }

    private function success(string $msg): void { echo $this->color($msg, 'green') . PHP_EOL; }
    private function warning(string $msg): void { echo $this->color($msg, 'yellow') . PHP_EOL; }
    private function error(string $msg): void   { echo $this->color($msg, 'red') . PHP_EOL; }
}
