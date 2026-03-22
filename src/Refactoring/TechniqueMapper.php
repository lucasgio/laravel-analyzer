<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Refactoring;

/**
 * Maps analyzer issues to concrete refactoring techniques from refactoring.guru.
 *
 * Each technique entry contains:
 *   - name       : Human-readable technique name
 *   - url        : refactoring.guru permalink
 *   - description: What the technique does
 *   - steps      : Ordered instructions for applying it
 *   - test_hint  : What the unit test should verify before refactoring
 *   - branch_slug: Segment used to generate the git branch name
 *   - risk       : LOW | MEDIUM | HIGH
 */
class TechniqueMapper
{
    /**
     * Returns the best-matching technique for a given issue, or null if unmapped.
     *
     * @param array{severity: string, file: string, line: int, message: string} $issue
     * @return array|null
     */
    public function map(array $issue): ?array
    {
        $message = $issue['message'];

        foreach ($this->rules() as $pattern => $technique) {
            if (stripos($message, $pattern) !== false) {
                return $technique;
            }
        }

        return null;
    }

    /**
     * Returns all matched techniques for a list of issues,
     * deduplicating by technique URL (same technique = one phase).
     *
     * @param  array[] $issues
     * @return array[] List of [issue, technique] pairs
     */
    public function mapAll(array $issues): array
    {
        $pairs = [];

        foreach ($issues as $issue) {
            $technique = $this->map($issue);
            if ($technique !== null) {
                $pairs[] = ['issue' => $issue, 'technique' => $technique];
            }
        }

        return $pairs;
    }

    // ------------------------------------------------------------------

    private function rules(): array
    {
        return [
            // ── Complexity ──────────────────────────────────────────────
            'High Cyclomatic Complexity' => [
                'name'        => 'Extract Method',
                'url'         => 'https://refactoring.guru/extract-method',
                'description' => 'Break the complex method into smaller, well-named private methods, each doing one thing.',
                'steps'       => [
                    'Identify the logical sub-blocks inside the method (validation, calculation, persistence, response).',
                    'For each sub-block, create a private method with a descriptive name.',
                    'Replace the original block with a call to the new method.',
                    'Ensure the method signature passes only the data it needs (avoid passing $this implicitly).',
                    'Run the full test suite to confirm no regression.',
                ],
                'test_hint'   => 'Write a test that calls the method with representative inputs and asserts the expected output before extracting. This becomes your regression guard.',
                'branch_slug' => 'extract-method',
                'risk'        => 'LOW',
            ],

            'deep nesting' => [
                'name'        => 'Decompose Conditional',
                'url'         => 'https://refactoring.guru/decompose-conditional',
                'description' => 'Replace complex if/else or nested conditionals with clearly named methods for condition and branch logic.',
                'steps'       => [
                    'Identify the condition that causes deep nesting.',
                    'Extract the condition expression into a private method named after what it checks (e.g., isEligibleForDiscount()).',
                    'Extract each branch body into its own method.',
                    'Use early returns (guard clauses) to invert nested if blocks and reduce indentation.',
                ],
                'test_hint'   => 'Test each branch of the conditional independently. Add one test per path through the nested conditions.',
                'branch_slug' => 'decompose-conditional',
                'risk'        => 'LOW',
            ],

            'duplicate' => [
                'name'        => 'Extract Method + Form Template Method',
                'url'         => 'https://refactoring.guru/form-template-method',
                'description' => 'Move duplicated logic into a shared method in a base class or trait, letting subclasses provide only what varies.',
                'steps'       => [
                    'Identify all files containing the duplicated logic.',
                    'Create a shared method (in a base class, trait, or helper) that contains the common code.',
                    'Parameterize any differences between the duplicate implementations.',
                    'Replace each duplicate with a call to the shared method.',
                    'Run all tests across the affected files.',
                ],
                'test_hint'   => 'Write one test per original duplicate site before extracting, asserting identical outputs for identical inputs.',
                'branch_slug' => 'dry-extract-shared-method',
                'risk'        => 'MEDIUM',
            ],

            // ── Coupling & Cohesion ─────────────────────────────────────
            'God Class' => [
                'name'        => 'Extract Class',
                'url'         => 'https://refactoring.guru/extract-class',
                'description' => 'Split the oversized class into multiple focused classes, each with a single responsibility.',
                'steps'       => [
                    'List all methods and properties; group them by the responsibility they serve.',
                    'Create a new class for each identified group.',
                    'Move fields and methods to the appropriate new class using Move Field / Move Method.',
                    'Replace direct field/method access in the original class with delegation calls to the new classes.',
                    'Inject the new classes via the constructor (Dependency Injection).',
                ],
                'test_hint'   => 'Document the current public API of the God Class in tests before splitting. Each public method needs at least one test that will pass unchanged after the refactor.',
                'branch_slug' => 'extract-class',
                'risk'        => 'HIGH',
            ],

            'high coupling' => [
                'name'        => 'Move Method / Introduce Parameter Object',
                'url'         => 'https://refactoring.guru/introduce-parameter-object',
                'description' => 'Group related parameters into a value object and move methods that use data from another class closer to that data.',
                'steps'       => [
                    'Identify which dependencies are tightly coupled (frequently called, data sent back and forth).',
                    'Create a Parameter Object (plain PHP class) grouping the related parameters.',
                    'Update the method signature to accept the Parameter Object.',
                    'If a method belongs more logically in the dependency class, use Move Method.',
                ],
                'test_hint'   => 'Test the method with different input combinations before and after refactoring to confirm behavior is identical.',
                'branch_slug' => 'introduce-parameter-object',
                'risk'        => 'MEDIUM',
            ],

            'Long method' => [
                'name'        => 'Extract Method',
                'url'         => 'https://refactoring.guru/extract-method',
                'description' => 'Break the long method into smaller private methods, each with a meaningful name.',
                'steps'       => [
                    'Read the method and annotate its logical sections in comments.',
                    'Extract each section into a private method named after its intent.',
                    'The original method becomes a high-level orchestration of the extracted calls.',
                    'Remove the comments — the method names now document the intent.',
                ],
                'test_hint'   => 'Cover the method\'s observable behaviour (return value, side effects) with tests before extracting.',
                'branch_slug' => 'extract-method-long',
                'risk'        => 'LOW',
            ],

            // ── SOLID / Refactoring ─────────────────────────────────────
            '[SRP]' => [
                'name'        => 'Extract Class + Move Method',
                'url'         => 'https://refactoring.guru/move-method',
                'description' => 'Split the class by responsibility: one class per concern (service, repository, event, response builder).',
                'steps'       => [
                    'List each responsibility handled by the class.',
                    'For each responsibility create a dedicated class (e.g., OrderService, OrderRepository, OrderNotifier).',
                    'Move methods and properties to the appropriate new class.',
                    'Inject the new classes via the constructor.',
                    'Update all call sites to use the new classes.',
                ],
                'test_hint'   => 'Write integration tests that cover the full workflow before splitting. After, write isolated unit tests per new class.',
                'branch_slug' => 'srp-extract-class',
                'risk'        => 'HIGH',
            ],

            '[OCP]' => [
                'name'        => 'Replace Conditional with Polymorphism',
                'url'         => 'https://refactoring.guru/replace-conditional-with-polymorphism',
                'description' => 'Replace switch/if-else chains that dispatch by type with a polymorphic class hierarchy or Strategy pattern.',
                'steps'       => [
                    'Define an interface (or abstract class) with the method(s) currently handled by the switch.',
                    'Create a concrete implementation of the interface for each case.',
                    'Register the implementations in a factory or use Laravel\'s IoC container.',
                    'Replace the switch with a call to the interface method on the resolved implementation.',
                    'New types now only require adding a new class — no modification to existing code.',
                ],
                'test_hint'   => 'Add one unit test per switch case before refactoring. After, test each concrete strategy class in isolation.',
                'branch_slug' => 'replace-conditional-polymorphism',
                'risk'        => 'MEDIUM',
            ],

            '[DIP]' => [
                'name'        => 'Extract Interface',
                'url'         => 'https://refactoring.guru/extract-interface',
                'description' => 'Define a PHP interface for each concrete dependency, then bind it in a ServiceProvider.',
                'steps'       => [
                    'Create a PHP interface in app/Contracts/ for each concrete class injected.',
                    'Make the concrete class implement the interface.',
                    'Update the constructor type-hint from the concrete class to the interface.',
                    'In AppServiceProvider::register(), add: $this->app->bind(MyInterface::class, MyConcreteClass::class).',
                    'This allows swapping implementations and mocking in tests.',
                ],
                'test_hint'   => 'Mock the interface in unit tests using Mockery or PHPUnit mocks instead of instantiating the concrete class.',
                'branch_slug' => 'extract-interface-dip',
                'risk'        => 'LOW',
            ],

            '[DI]' => [
                'name'        => 'Replace Constructor with Dependency Injection',
                'url'         => 'https://refactoring.guru/introduce-parameter-object',
                'description' => 'Remove manual instantiation (new ClassName()) and inject dependencies through the constructor.',
                'steps'       => [
                    'Add each manually instantiated dependency as a constructor parameter with a type-hint.',
                    'Remove the "new ClassName()" call from the method body.',
                    'Laravel\'s container will automatically resolve and inject the dependency.',
                    'If the class is not resolved by the container, register a factory in a ServiceProvider.',
                ],
                'test_hint'   => 'In tests, inject a mock or stub instead of the real dependency. This verifies the class is truly decoupled.',
                'branch_slug' => 'inject-dependencies',
                'risk'        => 'LOW',
            ],

            '[Actions]' => [
                'name'        => 'Extract Class → Action',
                'url'         => 'https://refactoring.guru/extract-class',
                'description' => 'Move business logic out of the controller into a dedicated single-action class with a handle() or __invoke() method.',
                'steps'       => [
                    'Create app/Actions/[Module]/[ActionName].php (e.g., CreateOrderAction).',
                    'Move the business logic from the controller method into the Action\'s handle() method.',
                    'Inject any dependencies the Action needs via its constructor.',
                    'The controller method becomes: return $this->action->handle($request->validated()).',
                    'Single-action controllers: rename the method to __invoke() and update the route to use ::class syntax.',
                ],
                'test_hint'   => 'Unit test the Action class in isolation with mocked dependencies. The controller test becomes a thin integration test.',
                'branch_slug' => 'extract-action-class',
                'risk'        => 'LOW',
            ],

            // ── Security ────────────────────────────────────────────────
            'SQL injection' => [
                'name'        => 'Substitute Algorithm — Parameterized Queries',
                'url'         => 'https://refactoring.guru/substitute-algorithm',
                'description' => 'Replace raw SQL string concatenation with Eloquent/Query Builder bindings or prepared statements.',
                'steps'       => [
                    'Replace DB::statement("SELECT * WHERE id = " . $id) with DB::select("SELECT * WHERE id = ?", [$id]).',
                    'Or use Eloquent: Model::where("id", $id)->first().',
                    'Never interpolate user input directly into SQL strings.',
                    'Run static analysis (Psalm/PHPStan) to catch remaining injection points.',
                ],
                'test_hint'   => 'Test with malicious inputs (e.g., "1 OR 1=1") to confirm the query does not return unintended rows.',
                'branch_slug' => 'fix-sql-injection',
                'risk'        => 'HIGH',
            ],

            'weak hash' => [
                'name'        => 'Substitute Algorithm — Secure Hashing',
                'url'         => 'https://refactoring.guru/substitute-algorithm',
                'description' => 'Replace MD5/SHA1 with Laravel\'s Hash::make() (bcrypt by default) or bin2hex(random_bytes()) for tokens.',
                'steps'       => [
                    'Replace md5($password) or sha1($password) with Hash::make($password).',
                    'For tokens/identifiers (not passwords), use Str::random(64) or bin2hex(random_bytes(32)).',
                    'Verify hashes with Hash::check($plain, $hashed) — never by re-hashing and comparing.',
                    'If existing hashes in the DB are MD5/SHA1, plan a migration: re-hash on next successful login.',
                ],
                'test_hint'   => 'Test that Hash::check() returns true for a value hashed with Hash::make(), and false for wrong values.',
                'branch_slug' => 'fix-weak-hashing',
                'risk'        => 'HIGH',
            ],

            'mass assignment' => [
                'name'        => 'Substitute Algorithm — Explicit Assignment',
                'url'         => 'https://refactoring.guru/substitute-algorithm',
                'description' => 'Remove $guarded = [] and define $fillable explicitly with only the fields the user should control.',
                'steps'       => [
                    'Replace protected $guarded = [] with protected $fillable = [\'field1\', \'field2\'].',
                    'List only the fields that should be user-assignable.',
                    'Use $model->forceFill() only when you control the data (e.g., system operations, not user input).',
                ],
                'test_hint'   => 'Test that attempting to set a non-fillable field via fill() is silently ignored (the field remains unchanged).',
                'branch_slug' => 'fix-mass-assignment',
                'risk'        => 'MEDIUM',
            ],

            'session' => [
                'name'        => 'Substitute Algorithm — Session Regeneration',
                'url'         => 'https://refactoring.guru/substitute-algorithm',
                'description' => 'Add session()->regenerate() after successful authentication to prevent session fixation attacks.',
                'steps'       => [
                    'After Auth::login($user) or auth()->attempt(), call session()->regenerate().',
                    'In Fortify/Breeze custom auth actions, ensure regeneration happens before the redirect.',
                    'Confirm the session ID in the cookie changes after login using browser dev tools.',
                ],
                'test_hint'   => 'Assert that after a login request, the session ID in the response cookie differs from the pre-login session ID.',
                'branch_slug' => 'fix-session-fixation',
                'risk'        => 'HIGH',
            ],

            'Technical Debt' => [
                'name'        => 'Remove Dead Code / Inline Method',
                'url'         => 'https://refactoring.guru/inline-method',
                'description' => 'Remove TODO/FIXME markers by either resolving the issue, deleting dead code, or inlining trivial methods.',
                'steps'       => [
                    'Search for all TODO/FIXME/HACK comments: grep -r "TODO\|FIXME\|HACK" app/',
                    'For each marker: (a) resolve it now, (b) create a GitHub issue and remove the comment, or (c) delete the dead code.',
                    'Commented-out code should be deleted — it\'s preserved in git history.',
                    'Trivial wrapper methods that just call another method can be inlined.',
                ],
                'test_hint'   => 'If resolving a TODO adds behaviour, write a test for the new behaviour before implementing it (TDD).',
                'branch_slug' => 'remove-technical-debt',
                'risk'        => 'LOW',
            ],
        ];
    }
}
