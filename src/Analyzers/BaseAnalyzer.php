<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

use LaravelAnalyzer\Config\LaravelFileFilter;

abstract class BaseAnalyzer
{
    protected array $issues = [];
    protected array $recommendations = [];
    protected string $projectPath = '';

    /** Per-file issue map: [ 'relative/path.php' => [ issue, … ] ] */
    protected array $fileIssues = [];

    abstract public function analyze(string $projectPath): array;

    /**
     * Returns PHP files, excluding framework dirs.
     * When $devOnly is true the LaravelFileFilter is applied so that
     * Laravel scaffold files are excluded from the results.
     */
    protected function getPhpFiles(string $path, array $excludeDirs = [], bool $devOnly = false): array
    {
        $files = [];
        if (!is_dir($path)) return $files;

        $defaultExclude = LaravelFileFilter::excludedDirs();
        $allExclude     = array_unique(array_merge($defaultExclude, $excludeDirs));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current) use ($allExclude) {
                    if ($current->isDir()) {
                        foreach ($allExclude as $excluded) {
                            if (str_contains($current->getPathname(), DIRECTORY_SEPARATOR . $excluded)) {
                                return false;
                            }
                        }
                    }
                    return true;
                }
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        if ($devOnly && $this->projectPath !== '') {
            $filter = new LaravelFileFilter($this->projectPath);
            $files  = $filter->filterDevFiles($files);
        }

        return $files;
    }

    /**
     * Same as getPhpFiles() with devOnly=true — convenience method for analyzers
     * that should only inspect developer-written code.
     */
    protected function getDevPhpFiles(string $path): array
    {
        return $this->getPhpFiles($path, [], true);
    }

    protected function getFilesByPattern(string $path, string $pattern): array
    {
        $result = [];
        $flags = \FilesystemIterator::SKIP_DOTS;
        if (!is_dir($path)) return $result;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, $flags)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $result[] = $file->getPathname();
            }
        }

        return $result;
    }

    protected function readFile(string $path): string
    {
        return file_exists($path) ? file_get_contents($path) : '';
    }

    protected function countLines(string $content): int
    {
        return substr_count($content, PHP_EOL) + 1;
    }

    protected function extractClassName(string $content): ?string
    {
        if (preg_match('/(?:class|interface|trait|enum)\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function extractNamespace(string $content): ?string
    {
        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function scoreToRisk(float $score): string
    {
        return match(true) {
            $score >= 80 => 'LOW',
            $score >= 60 => 'MEDIUM',
            $score >= 40 => 'HIGH',
            default      => 'CRITICAL',
        };
    }

    protected function addIssue(string $severity, string $file, string $message, int $line = 0): void
    {
        $entry = [
            'severity' => $severity,
            'file'     => $file,
            'line'     => $line,
            'message'  => $message,
        ];

        $this->issues[] = $entry;

        // Also index by file for the per-file report
        $relativeFile = $this->toRelativePath($file);
        $this->fileIssues[$relativeFile][] = $entry;
    }

    private function toRelativePath(string $file): string
    {
        if ($this->projectPath !== '' && str_starts_with($file, $this->projectPath . '/')) {
            return substr($file, strlen($this->projectPath) + 1);
        }
        return $file;
    }

    protected function addRecommendation(string $message): void
    {
        $this->recommendations[] = $message;
    }

    protected function buildResult(float $score, array $metrics, string $summary): array
    {
        return [
            'score'           => round($score, 2),
            'risk'            => $this->scoreToRisk($score),
            'summary'         => $summary,
            'metrics'         => $metrics,
            'issues'          => $this->issues,
            'file_issues'     => $this->fileIssues,
            'recommendations' => $this->recommendations,
        ];
    }
}
