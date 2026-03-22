<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Config;

use LaravelAnalyzer\Config\LaravelFileFilter;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class LaravelFileFilterTest extends TestCase
{
    use TempProjectTrait;

    private LaravelFileFilter $filter;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->filter = new LaravelFileFilter($this->tempPath());
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    // ------------------------------------------------------------------
    // isDevFile — framework exclusions
    // ------------------------------------------------------------------

    public function testExcludesHttpKernel(): void
    {
        $file = $this->tempPath('app/Http/Kernel.php');
        $this->assertFalse($this->filter->isDevFile($file));
    }

    public function testExcludesConsoleKernel(): void
    {
        $file = $this->tempPath('app/Console/Kernel.php');
        $this->assertFalse($this->filter->isDevFile($file));
    }

    public function testExcludesExceptionHandler(): void
    {
        $file = $this->tempPath('app/Exceptions/Handler.php');
        $this->assertFalse($this->filter->isDevFile($file));
    }

    public function testExcludesFrameworkMiddleware(): void
    {
        $middleware = [
            'app/Http/Middleware/Authenticate.php',
            'app/Http/Middleware/RedirectIfAuthenticated.php',
            'app/Http/Middleware/TrustHosts.php',
            'app/Http/Middleware/TrustProxies.php',
            'app/Http/Middleware/VerifyCsrfToken.php',
            'app/Http/Middleware/EncryptCookies.php',
            'app/Http/Middleware/PreventRequestsDuringMaintenance.php',
        ];

        foreach ($middleware as $relative) {
            $this->assertFalse(
                $this->filter->isDevFile($this->tempPath($relative)),
                "Expected {$relative} to be excluded"
            );
        }
    }

    public function testExcludesBroadcastServiceProvider(): void
    {
        $file = $this->tempPath('app/Providers/BroadcastServiceProvider.php');
        $this->assertFalse($this->filter->isDevFile($file));
    }

    public function testExcludesIdeHelperByGlob(): void
    {
        $this->assertFalse($this->filter->isDevFile($this->tempPath('_ide_helper.php')));
        $this->assertFalse($this->filter->isDevFile($this->tempPath('_ide_helper_models.php')));
    }

    // ------------------------------------------------------------------
    // isDevFile — developer files are kept
    // ------------------------------------------------------------------

    public function testKeepsDeveloperControllers(): void
    {
        $file = $this->tempPath('app/Http/Controllers/UserController.php');
        $this->assertTrue($this->filter->isDevFile($file));
    }

    public function testKeepsDeveloperModels(): void
    {
        $file = $this->tempPath('app/Models/User.php');
        $this->assertTrue($this->filter->isDevFile($file));
    }

    public function testKeepsDeveloperServices(): void
    {
        $file = $this->tempPath('app/Services/PaymentService.php');
        $this->assertTrue($this->filter->isDevFile($file));
    }

    public function testKeepsCustomMiddleware(): void
    {
        $file = $this->tempPath('app/Http/Middleware/CheckSubscription.php');
        $this->assertTrue($this->filter->isDevFile($file));
    }

    public function testKeepsCustomProviders(): void
    {
        $file = $this->tempPath('app/Providers/AppServiceProvider.php');
        $this->assertTrue($this->filter->isDevFile($file));
    }

    // ------------------------------------------------------------------
    // filterDevFiles
    // ------------------------------------------------------------------

    public function testFilterDevFilesRemovesFrameworkFiles(): void
    {
        $files = [
            $this->tempPath('app/Http/Controllers/OrderController.php'),
            $this->tempPath('app/Http/Kernel.php'),
            $this->tempPath('app/Models/Order.php'),
            $this->tempPath('app/Exceptions/Handler.php'),
            $this->tempPath('_ide_helper.php'),
        ];

        $result = $this->filter->filterDevFiles($files);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('OrderController.php', $result[0]);
        $this->assertStringContainsString('Order.php', $result[1]);
    }

    public function testFilterDevFilesReturnsAllWhenNoneExcluded(): void
    {
        $files = [
            $this->tempPath('app/Models/User.php'),
            $this->tempPath('app/Services/AuthService.php'),
        ];

        $result = $this->filter->filterDevFiles($files);
        $this->assertCount(2, $result);
    }

    public function testFilterDevFilesReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->filter->filterDevFiles([]));
    }

    // ------------------------------------------------------------------
    // Custom config via .laravel-analyzer.json
    // ------------------------------------------------------------------

    public function testLoadsCustomExclusionsFromConfig(): void
    {
        $this->createFile('.laravel-analyzer.json', json_encode([
            'exclude' => ['app/Legacy/OldKernel.php'],
        ]));

        $filter = new LaravelFileFilter($this->tempPath());
        $file   = $this->tempPath('app/Legacy/OldKernel.php');

        $this->assertFalse($filter->isDevFile($file));
    }

    public function testCustomGlobExclusionPattern(): void
    {
        $this->createFile('.laravel-analyzer.json', json_encode([
            'exclude' => ['app/Generated/*.php'],
        ]));

        $filter = new LaravelFileFilter($this->tempPath());

        $this->assertFalse($filter->isDevFile($this->tempPath('app/Generated/AutoModel.php')));
        $this->assertTrue($filter->isDevFile($this->tempPath('app/Models/User.php')));
    }

    public function testIgnoresMissingConfig(): void
    {
        // No .laravel-analyzer.json — should not throw
        $filter = new LaravelFileFilter($this->tempPath());
        $file   = $this->tempPath('app/Models/User.php');

        $this->assertTrue($filter->isDevFile($file));
    }

    public function testIgnoresMalformedConfig(): void
    {
        $this->createFile('.laravel-analyzer.json', 'not valid json {{{');

        $filter = new LaravelFileFilter($this->tempPath());
        $file   = $this->tempPath('app/Models/User.php');

        // Should not throw, just ignore bad config
        $this->assertTrue($filter->isDevFile($file));
    }

    // ------------------------------------------------------------------
    // excludedDirs static method
    // ------------------------------------------------------------------

    public function testExcludedDirsContainsVendor(): void
    {
        $this->assertContains('vendor', LaravelFileFilter::excludedDirs());
    }

    public function testExcludedDirsContainsStorage(): void
    {
        $this->assertContains('storage', LaravelFileFilter::excludedDirs());
    }
}
