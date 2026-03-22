<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Skills;

class LaravelApiSkillContent
{
    public static function claude(): string
    {
        return <<<'MD'
# Laravel API REST Best Practices

Apply the following standards when building or modifying API endpoints in this project.

---

## Versioning

- Always version the API with a URL prefix: `/api/v1/`, `/api/v2/`.
- Use route groups in `routes/api.php`:

```php
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::apiResource('orders', OrderController::class);
});
```

- Never break v1 contracts when introducing v2. Run both versions in parallel during transition.

## Routes

- Use `apiResource()` for CRUD — it generates only the 5 API routes (no `create`/`edit`).
- Prefer explicit routes over magic for custom actions: `Route::post('orders/{order}/cancel', ...)`.
- Apply `throttle` middleware on all public routes: `middleware(['auth:sanctum', 'throttle:60,1'])`.
- Group routes by authentication requirement.

```php
// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('orders', OrderController::class);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
});
```

## Controllers

- API controllers must be **thin**: validate → delegate to Action/Service → return Resource.
- Extend `App\Http\Controllers\Controller` (not `BaseController` or custom abstractions unless already established).
- One controller per resource. Extra actions (cancel, approve) go as additional methods, not new controllers.

```php
class OrderController extends Controller
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly UpdateOrderAction $updateOrder,
    ) {}

    public function store(StoreOrderRequest $request): OrderResource
    {
        $order = $this->createOrder->handle($request->validated());
        return new OrderResource($order);
    }
}
```

## API Resources

- **Always** return data through API Resources: `php artisan make:resource OrderResource`.
- Use Resource Collections for lists: `OrderResource::collection($orders)`.
- Never return `$model->toArray()` or raw `response()->json($model)` directly.
- Add `withResponse()` to set headers or status on the resource when needed.

```php
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'status'     => $this->status,
            'total'      => $this->total_formatted,
            'created_at' => $this->created_at->toIso8601String(),
            'items'      => OrderItemResource::collection($this->whenLoaded('items')),
            'customer'   => new UserResource($this->whenLoaded('user')),
        ];
    }
}
```

## HTTP Status Codes

Always use semantically correct status codes:

| Situation | Code |
|---|---|
| Resource created | 201 Created |
| Successful read/update | 200 OK |
| Deleted | 204 No Content |
| Validation failed | 422 Unprocessable Entity |
| Unauthenticated | 401 Unauthorized |
| Unauthorized action | 403 Forbidden |
| Not found | 404 Not Found |
| Rate limit exceeded | 429 Too Many Requests |
| Server error | 500 Internal Server Error |

```php
return new OrderResource($order)
    ->response()
    ->setStatusCode(201);
```

## Error Handling

- Use Laravel's `Handler.php` (or Laravel 11's `bootstrap/app.php` `withExceptions()`) to return consistent JSON errors.
- Every API error must follow a consistent envelope:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

- Use `abort(404)` — Laravel will format it correctly for API routes when `Accept: application/json`.
- Catch domain exceptions and convert them to HTTP responses in a custom exception handler.

## Pagination

- Always paginate list endpoints. Never return unbounded collections.
- Use `->paginate(perPage: $request->integer('per_page', 15))`.
- Expose pagination meta through the Resource Collection:

```php
return OrderResource::collection($orders->paginate(15));
// Returns: { data: [...], links: {...}, meta: { total, per_page, current_page } }
```

## Filtering, Sorting & Searching

- Accept filters as query parameters: `GET /api/v1/orders?status=pending&sort=-created_at`.
- Use Eloquent scopes for filtering.
- Consider `spatie/laravel-query-builder` for complex filter/sort/include requirements.

```php
// Manual filter pattern
$orders = Order::query()
    ->when($request->status,   fn($q, $v) => $q->where('status', $v))
    ->when($request->search,   fn($q, $v) => $q->where('name', 'like', "%{$v}%"))
    ->when($request->sort,     fn($q, $v) => $q->orderBy(ltrim($v, '-'), str_starts_with($v, '-') ? 'desc' : 'asc'))
    ->paginate();
```

## Authentication

- Use **Laravel Sanctum** for SPA and mobile API authentication.
- Use **Laravel Passport** only when OAuth2 is a hard requirement.
- Token abilities (scopes) must be verified on every protected route.
- Never store tokens in localStorage — use httpOnly cookies for SPAs.

## Rate Limiting

- Define named rate limiters in `AppServiceProvider` or `RouteServiceProvider`:

```php
RateLimiter::for('api', function (Request $request) {
    return $request->user()
        ? Limit::perMinute(60)->by($request->user()->id)
        : Limit::perMinute(10)->by($request->ip());
});
```

## Request Validation

- Use Form Requests for all endpoints: `php artisan make:request StoreOrderRequest`.
- Always validate nested arrays explicitly: `'items.*.product_id' => ['required', 'exists:products,id']`.
- Use `stopOnFirstFailure()` for forms where early exit improves UX.

## Testing API Endpoints

- Test every endpoint: happy path, validation errors, auth failures, not-found.
- Use `$this->actingAs($user, 'sanctum')` for authenticated tests.
- Assert JSON structure with `assertJsonStructure()` and status with `assertStatus()`.

```php
public function testStoreCreatesOrder(): void
{
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
         ->postJson('/api/v1/orders', [
             'items' => [['product_id' => 1, 'quantity' => 2]],
         ])
         ->assertStatus(201)
         ->assertJsonStructure([
             'data' => ['id', 'status', 'total', 'created_at'],
         ]);
}
```
MD;
    }

    public static function cursor(): string
    {
        return <<<'MDC'
---
description: Laravel API REST Best Practices
globs: app/Http/Controllers/**/*.php, routes/api.php
alwaysApply: false
---

# Laravel API REST Best Practices

## Routes
- Always version: `/api/v1/`, `/api/v2/`
- Use `apiResource()` for CRUD
- Apply `throttle` and `auth:sanctum` to all protected routes

## Controllers
- Thin controllers: validate → Action → Resource
- No business logic in controllers
- Inject Actions via constructor

## API Resources
- Always return through API Resources — never raw models or arrays
- Use `whenLoaded()` for relationships to avoid N+1
- Resource Collections for lists

## HTTP Status Codes
- 201 for created, 204 for deleted, 422 for validation, 401/403 for auth issues

## Errors
- Consistent JSON error envelope: `{ message, errors }`
- Use Laravel's exception handler for domain → HTTP mapping

## Pagination
- Always paginate list endpoints — never return unbounded collections
- `paginate(perPage: $request->integer('per_page', 15))`

## Validation
- Always use Form Requests
- Validate nested arrays explicitly

## Testing
- Test every status code path: 200, 201, 401, 403, 404, 422
- Use `actingAs($user, 'sanctum')` for auth tests
- Assert JSON structure and status
MDC;
    }
}
