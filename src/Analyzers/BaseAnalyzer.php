<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Analyzers;

abstract class BaseAnalyzer
{
    protected array $issues = [];
    protected array $recommendations = [];
    protected string $projectPath = '';

    abstract public function analyze(string $projectPath): array;

    protected function getPhpFiles(string $path, array $excludeDirs = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache']): array
    {
        $files = [];
        if (!is_dir($path)) return $files;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current) use ($excludeDirs) {
                    if ($current->isDir()) {
                        foreach ($excludeDirs as $excluded) {
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

        return $files;
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
            $score >= 80 => 'BAJO',
            $score >= 60 => 'MEDIO',
            $score >= 40 => 'ALTO',
            default      => 'CRÍTICO',
        };
    }

    protected function addIssue(string $severity, string $file, string $message, int $line = 0): void
    {
        $this->issues[] = [
            'severity' => $severity,
            'file'     => $file,
            'line'     => $line,
            'message'  => $message,
        ];
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
            'recommendations' => $this->recommendations,
        ];
    }
}
