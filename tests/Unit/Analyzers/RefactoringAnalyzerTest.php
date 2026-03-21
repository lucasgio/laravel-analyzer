<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Tests\Unit\Analyzers;

use LaravelAnalyzer\Analyzers\RefactoringAnalyzer;
use LaravelAnalyzer\Tests\Support\TempProjectTrait;
use PHPUnit\Framework\TestCase;

class RefactoringAnalyzerTest extends TestCase
{
    use TempProjectTrait;

    private RefactoringAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->setUpTempProject();
        $this->analyzer = new RefactoringAnalyzer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempProject();
    }

    // ------------------------------------------------------------------
    // Contract
    // ------------------------------------------------------------------

    public function testResultHasRequiredKeys(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('file_issues', $result);
    }

    public function testScoreIsBetweenZeroAndHundred(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testEmptyProjectScoreIsHundred(): void
    {
        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(100.0, $result['score']);
        $this->assertSame(0, $result['metrics']['files_analyzed']);
    }

    // ------------------------------------------------------------------
    // SRP detection
    // ------------------------------------------------------------------

    public function testDetectsSrpViolation(): void
    {
        $this->createFile('app/Http/Controllers/GodController.php', '<?php
namespace App\Http\Controllers;
class GodController {
    public function handle() {
        DB::table("users")->where("id", 1)->save();
        return response()->json([]);
        Mail::send("welcome", [], fn($m) => $m);
        Storage::put("file.txt", "data");
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['srp_violations']);
        $this->assertNotEmpty($result['recommendations']);
    }

    public function testCleanClassPassesSrp(): void
    {
        $this->createFile('app/Services/PureCalculator.php', '<?php
namespace App\Services;
class PureCalculator {
    public function add(int $a, int $b): int {
        return $a + $b;
    }
    public function multiply(int $a, int $b): int {
        return $a * $b;
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(0, $result['metrics']['srp_violations']);
    }

    // ------------------------------------------------------------------
    // OCP detection
    // ------------------------------------------------------------------

    public function testDetectsOcpViolationWithManySwitches(): void
    {
        $this->createFile('app/Services/TypeHandler.php', '<?php
namespace App\Services;
class TypeHandler {
    public function handleA($type) {
        switch ($type) {
            case "a": return 1;
            case "b": return 2;
        }
    }
    public function handleB($type) {
        switch ($type) {
            case "x": return 10;
            case "y": return 20;
        }
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['ocp_violations']);
    }

    public function testDetectsOcpViolationWithManyElseif(): void
    {
        $this->createFile('app/Services/ElseifService.php', '<?php
namespace App\Services;
class ElseifService {
    public function process($type) {
        if ($type === "a") { return 1; }
        elseif ($type === "b") { return 2; }
        elseif ($type === "c") { return 3; }
        elseif ($type === "d") { return 4; }
        elseif ($type === "e") { return 5; }
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['ocp_violations']);
    }

    // ------------------------------------------------------------------
    // DIP detection
    // ------------------------------------------------------------------

    public function testDetectsDipViolation(): void
    {
        $this->createFile('app/Services/OrderService.php', '<?php
namespace App\Services;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Repositories\ProductRepository;
class OrderService {
    public function __construct(
        OrderRepository $orders,
        UserRepository $users,
        ProductRepository $products
    ) {}
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['dip_violations']);
    }

    public function testInterfaceInjectionPassesDip(): void
    {
        $this->createFile('app/Services/CleanService.php', '<?php
namespace App\Services;
use App\Contracts\OrderRepositoryInterface;
use App\Contracts\UserRepositoryInterface;
class CleanService {
    public function __construct(
        OrderRepositoryInterface $orders,
        UserRepositoryInterface $users
    ) {}
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(0, $result['metrics']['dip_violations']);
    }

    // ------------------------------------------------------------------
    // Missing DI detection
    // ------------------------------------------------------------------

    public function testDetectsNewInstantiationInBusinessClass(): void
    {
        $this->createFile('app/Services/BadService.php', '<?php
namespace App\Services;
class BadService {
    public function handle() {
        $repo = new UserRepository();
        $mailer = new Mailer();
        return $repo->all();
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['missing_di_occurrences']);
    }

    public function testSkipsMissingDiCheckForProviders(): void
    {
        $this->createFile('app/Providers/AppServiceProvider.php', '<?php
namespace App\Providers;
class AppServiceProvider {
    public function register() {
        $this->app->bind("foo", fn() => new FooService());
    }
}');

        $before = $this->analyzer->analyze($this->tempPath())['metrics']['missing_di_occurrences'];
        $this->assertSame(0, $before);
    }

    // ------------------------------------------------------------------
    // Laravel Actions — fat controller
    // ------------------------------------------------------------------

    public function testDetectsFatController(): void
    {
        $longMethod = str_repeat("        \$x = 1;\n", 25);

        $this->createFile('app/Http/Controllers/FatController.php', "<?php
namespace App\\Http\\Controllers;
class FatController extends Controller {
    public function store(\$request) {
{$longMethod}        return response()->json([]);
    }
    public function update(\$request, \$id) {
{$longMethod}        return response()->json([]);
    }
}");

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['fat_controllers']);
    }

    // ------------------------------------------------------------------
    // Laravel Actions — single-action controller
    // ------------------------------------------------------------------

    public function testDetectsSingleActionControllerCandidate(): void
    {
        $this->createFile('app/Http/Controllers/ShowDashboard.php', '<?php
namespace App\Http\Controllers;
class ShowDashboard extends Controller {
    public function index() {
        return view("dashboard");
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['single_action_candidates']);
    }

    public function testInvokeControllerPassesSingleActionCheck(): void
    {
        $this->createFile('app/Http/Controllers/ShowDashboard.php', '<?php
namespace App\Http\Controllers;
class ShowDashboard extends Controller {
    public function __invoke() {
        return view("dashboard");
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertSame(0, $result['metrics']['single_action_candidates']);
    }

    // ------------------------------------------------------------------
    // DRY detection
    // ------------------------------------------------------------------

    public function testDetectsDuplicatedLogicAcrossFiles(): void
    {
        // Body must normalize to >=120 chars to be considered meaningful by the DRY detector
        $duplicatedBody = '
        $validItems = [];
        foreach ($data as $item) {
            if ($item["active"] === true && $item["score"] > 50 && isset($item["id"])) {
                $validItems[] = ["id" => $item["id"], "name" => $item["name"], "score" => $item["score"], "rank" => $item["rank"] ?? 0];
            }
        }
        usort($validItems, function($a, $b) { return $b["score"] <=> $a["score"]; });
        return array_values(array_slice($validItems, 0, 10));';

        $this->createFile('app/Services/OrderService.php', "<?php
class OrderService { public function getTopActive(\$data) {{$duplicatedBody}} }");

        $this->createFile('app/Services/ProductService.php', "<?php
class ProductService { public function getTopActive(\$data) {{$duplicatedBody}} }");

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertGreaterThan(0, $result['metrics']['dry_violations']);
    }

    // ------------------------------------------------------------------
    // File issues map
    // ------------------------------------------------------------------

    public function testFileIssuesAreIndexedByRelativePath(): void
    {
        $this->createFile('app/Http/Controllers/GodController.php', '<?php
class GodController extends Controller {
    public function handle() {
        DB::table("x")->where("id", 1)->save();
        return response()->json([]);
        Mail::send("welcome", [], fn($m) => $m);
        Storage::put("f", "d");
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        $this->assertArrayHasKey('file_issues', $result);
        // At least one file should have issues indexed
        $this->assertNotEmpty($result['file_issues']);
    }

    // ------------------------------------------------------------------
    // Framework file exclusion via LaravelFileFilter
    // ------------------------------------------------------------------

    public function testSkipsFrameworkFiles(): void
    {
        // Kernel.php is a framework file and should never be analyzed
        $this->createFile('app/Http/Kernel.php', '<?php
class Kernel {
    public function process() {
        DB::table("x")->where("id",1)->save();
        return response()->json([]);
        Mail::send("x", [], fn($m) => $m);
        Storage::put("f","d");
    }
}');

        $result = $this->analyzer->analyze($this->tempPath());

        // Score should be 100 — no developer files were found to violate
        $this->assertSame(100.0, $result['score']);
        $this->assertSame(0, $result['metrics']['files_analyzed']);
    }
}
