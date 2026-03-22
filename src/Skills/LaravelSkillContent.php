<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Skills;

class LaravelSkillContent
{
    public static function claude(): string
    {
        return <<<'MD'
# Laravel General Best Practices

Apply the following standards when writing any Laravel code in this project.

---

## Project Structure

- Follow the **modular structure**: group related files under `app/Modules/{Module}/` with sub-folders Controllers, Models, Services, Actions, Events, Jobs, Policies.
- Never place business logic in Controllers, Middleware, or Models — use Services or Actions.
- Name classes after what they **do**, not what they extend: `CreateOrderAction`, not `OrderController`.

## Models

- Always define `$fillable` explicitly. Never use `$guarded = []`.
- Define `$casts` for dates, booleans, enums, and JSON columns.
- Use Eloquent relationships (hasMany, belongsTo, morphTo) — never raw JOIN queries in application code.
- Scope repetitive query conditions: `scopeActive($query)`, `scopeForTenant($query, $tenantId)`.
- Keep models **thin**: no business logic, only database interaction and relationships.

```php
// Good
protected $fillable = ['name', 'email', 'status'];
protected $casts    = ['email_verified_at' => 'datetime', 'is_active' => 'boolean'];
```

## Services & Actions

- **Service**: orchestrates multiple operations (e.g., `OrderService` coordinates inventory, payment, notification).
- **Action**: single operation with one `handle()` or `__invoke()` method (e.g., `CreateOrderAction`).
- Inject all dependencies via the constructor. Never use `new ClassName()` inside a method.
- Return typed values or DTOs — never raw arrays when structure matters.

```php
// Action pattern
class CreateOrderAction
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly InventoryService $inventory,
    ) {}

    public function handle(CreateOrderData $data): Order
    {
        $this->inventory->reserve($data->items);
        return $this->orders->create($data);
    }
}
```

## Dependency Injection

- Always inject via constructor, never use `app()`, `resolve()`, or `App::make()` in business classes.
- Define interfaces in `app/Contracts/` and bind them in a ServiceProvider.
- Use Laravel's automatic injection: type-hint in controller constructors and route closures.

## Validation

- Always use **Form Request** classes: `php artisan make:request StoreOrderRequest`.
- Put authorization (`authorize()`) and validation (`rules()`) together in the Form Request.
- Use custom Rule objects for complex validations: `php artisan make:rule UniqueSlug`.

```php
// Form Request
public function rules(): array
{
    return [
        'name'     => ['required', 'string', 'max:255'],
        'email'    => ['required', 'email', 'unique:users,email'],
        'items'    => ['required', 'array', 'min:1'],
        'items.*'  => ['exists:products,id'],
    ];
}
```

## Events & Listeners

- Raise events for significant domain actions: `OrderPlaced`, `UserRegistered`, `PaymentFailed`.
- Keep listeners focused on one side effect each.
- Use `ShouldQueue` on listeners for non-critical side effects (email, analytics, notifications).

## Jobs & Queues

- Every long-running or non-critical operation must be a queued job.
- Use `$tries`, `$maxExceptions`, and `$backoff` on every job.
- Use `onQueue()` to separate concerns: emails on `notifications`, imports on `heavy`.
- Always implement `failed(Throwable $e)` for proper error handling.

## Database & Migrations

- One migration per logical change. Never modify existing migrations after they are run.
- Always add foreign key constraints in migrations.
- Use database transactions for multi-step writes: `DB::transaction(fn() => ...)`.
- Never use `Schema::dropIfExists` in non-rollback context.

## Configuration

- Never hardcode values: use `config('app.key')` → `.env` → `config/app.php`.
- Group related config in a dedicated file: `config/services.php`, `config/billing.php`.
- Cache config in production: `php artisan config:cache`.

## Testing

- Every public method in a Service or Action must have a unit test.
- Use `RefreshDatabase` in feature tests, factories for test data.
- Mock external services (payment gateways, email) — never hit real services in tests.
- Aim for ≥80% code coverage on `app/` directory.
MD;
    }

    public static function cursor(): string
    {
        return <<<'MDC'
---
description: Laravel General Best Practices — applied to all PHP files in app/
globs: app/**/*.php, database/migrations/*.php, routes/*.php
alwaysApply: false
---

# Laravel General Best Practices

## Models
- Always use `$fillable`, never `$guarded = []`
- Define `$casts` for dates, booleans, JSON, enums
- Relationships over raw JOINs
- Thin models: no business logic

## Actions & Services
- One Action = one operation, one `handle()` method
- Inject all dependencies via constructor
- No `new ClassName()` inside methods
- Return typed values or DTOs

## Validation
- Always use Form Requests
- Complex rules → custom Rule objects
- Authorization in `authorize()`, not in the controller

## Events
- Raise events for domain actions
- Listeners: one side effect each
- Non-critical listeners must implement ShouldQueue

## Jobs
- All async work must be a queued Job
- Always set `$tries`, `$backoff`, implement `failed()`

## Testing
- Unit test every Service and Action
- Feature tests use RefreshDatabase and factories
- Mock external services — never real HTTP in tests
MDC;
    }
}
