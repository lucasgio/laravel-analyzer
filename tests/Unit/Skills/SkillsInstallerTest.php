<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Skills;

use LaravelAnalyzer\Skills\SkillsInstaller;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class SkillsInstallerTest extends TestCase
{
    use TempProjectTrait;

    protected function setUp(): void
    {
        $this->setUpTempProject();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    public function testInstallReturnsExpectedKeys(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $result    = $installer->install();

        $this->assertArrayHasKey('installed', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('log', $result);
    }

    public function testInstallCreatesClaudeCommandFiles(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install();

        $skills = ['laravel', 'laravel-api', 'react', 'vue-inertia'];
        foreach ($skills as $key) {
            $file = $this->tempPath(".claude/commands/{$key}.md");
            $this->assertFileExists($file, "Expected Claude command file for skill: {$key}");
        }
    }

    public function testInstallCreatesCursorRuleFiles(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install();

        $skills = ['laravel', 'laravel-api', 'react', 'vue-inertia'];
        foreach ($skills as $key) {
            $file = $this->tempPath(".cursor/rules/{$key}.mdc");
            $this->assertFileExists($file, "Expected Cursor rule file for skill: {$key}");
        }
    }

    public function testInstallCreatesSetupGuide(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install();

        $this->assertFileExists($this->tempPath('AGENT_SETUP.md'));
    }

    public function testSetupGuideContainsUsageInstructions(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install();

        $content = file_get_contents($this->tempPath('AGENT_SETUP.md'));
        $this->assertStringContainsString('Claude Code', $content);
        $this->assertStringContainsString('Cursor', $content);
        $this->assertStringContainsString('/laravel', $content);
    }

    public function testInstallWithOnlyFilterInstallsSubset(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $result    = $installer->install(['laravel', 'react']);

        $this->assertCount(2, $result['installed']);
        $this->assertCount(2, $result['skipped']);

        $this->assertFileExists($this->tempPath('.claude/commands/laravel.md'));
        $this->assertFileExists($this->tempPath('.claude/commands/react.md'));
        $this->assertFileDoesNotExist($this->tempPath('.claude/commands/laravel-api.md'));
        $this->assertFileDoesNotExist($this->tempPath('.claude/commands/vue-inertia.md'));
    }

    public function testInstallWithOnlyFilterSkipsOtherSkills(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $result    = $installer->install(['laravel-api']);

        $this->assertCount(1, $result['installed']);
        $this->assertSame('Laravel API REST Best Practices', $result['installed'][0]);
    }

    public function testInstallWithEmptyOnlyArraySkipsAllSkills(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $result    = $installer->install([]);

        $this->assertCount(0, $result['installed']);
        $this->assertCount(4, $result['skipped']);
    }

    public function testInstallLogsEachInstalledFile(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $result    = $installer->install(['laravel']);

        $log = implode("\n", $result['log']);
        $this->assertStringContainsString('laravel.md', $log);
        $this->assertStringContainsString('laravel.mdc', $log);
        $this->assertStringContainsString('AGENT_SETUP.md', $log);
    }

    public function testInstallCreatesDirectoriesIfMissing(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install(['laravel']);

        $this->assertDirectoryExists($this->tempPath('.claude/commands'));
        $this->assertDirectoryExists($this->tempPath('.cursor/rules'));
    }

    public function testInstallNullInstallsAll(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $result    = $installer->install(null);

        $this->assertCount(4, $result['installed']);
        $this->assertCount(0, $result['skipped']);
    }

    public function testClaudeCommandFileContainsMarkdown(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install(['laravel']);

        $content = file_get_contents($this->tempPath('.claude/commands/laravel.md'));
        $this->assertStringContainsString('# Laravel', $content);
        $this->assertStringContainsString('Best Practices', $content);
    }

    public function testCursorRuleFileContainsFrontmatter(): void
    {
        $installer = new SkillsInstaller($this->tempPath());
        $installer->install(['laravel']);

        $content = file_get_contents($this->tempPath('.cursor/rules/laravel.mdc'));
        $this->assertStringContainsString('---', $content);
        $this->assertStringContainsString('description:', $content);
    }

    public function testAvailableSkillsReturnsAllFour(): void
    {
        $skills = SkillsInstaller::availableSkills();

        $this->assertArrayHasKey('laravel', $skills);
        $this->assertArrayHasKey('laravel-api', $skills);
        $this->assertArrayHasKey('react', $skills);
        $this->assertArrayHasKey('vue-inertia', $skills);
        $this->assertCount(4, $skills);
    }

    public function testInstallWithTrailingSlashInProjectPath(): void
    {
        $installer = new SkillsInstaller($this->tempPath() . '/');
        $result    = $installer->install(['laravel']);

        $this->assertCount(1, $result['installed']);
        $this->assertFileExists($this->tempPath('.claude/commands/laravel.md'));
    }
}
