<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Support;

trait TempProjectTrait
{
    private string $tempDir;

    protected function setUpTempProject(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/la-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDownTempProject(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    protected function createFile(string $relativePath, string $content): string
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
        return $fullPath;
    }

    protected function tempPath(string $relativePath = ''): string
    {
        return $this->tempDir . ($relativePath !== '' ? '/' . $relativePath : '');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
