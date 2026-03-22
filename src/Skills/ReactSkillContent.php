<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Skills;

class ReactSkillContent
{
    public static function claude(): string
    {
        return <<<'MD'
# React Best Practices

Apply the following standards when writing React components and logic in this project.

---

## Component Architecture

- **One component per file**, named identically to the file: `UserCard.tsx` → `export default function UserCard`.
- Prefer **function components** — no class components.
- Split into three layers:
  - **Page components** (`pages/`): data fetching, layout composition, no UI logic.
  - **Feature components** (`components/[Feature]/`): domain-specific UI (forms, tables, modals).
  - **UI components** (`components/ui/`): generic, reusable, no business logic (Button, Input, Badge).
- Components must do **one thing**. If a component exceeds ~150 lines, split it.

## Props

- Always type props with TypeScript interfaces — never use `any`.
- Destructure props directly in the function signature.
- Use default parameter values instead of `defaultProps`.

```tsx
interface UserCardProps {
  user: User;
  onSelect?: (id: number) => void;
  compact?: boolean;
}

export default function UserCard({ user, onSelect, compact = false }: UserCardProps) {
  return (/* ... */);
}
```

## State Management

- **Local state**: `useState` for UI state (open/closed, form field values).
- **Derived state**: compute from existing state/props — don't duplicate state.
- **Server state**: use a data-fetching library (React Query / SWR) — never store fetched data in `useState` directly.
- **Global state**: use React Context for cross-cutting concerns (auth, theme). Avoid prop drilling beyond 2 levels.
- Never mutate state directly: always create a new reference (`setState([...prev, newItem])`).

## Custom Hooks

- Extract any stateful logic that is reused or complex into a custom hook: `useOrderFilters`, `useDebounce`, `useLocalStorage`.
- Hooks must start with `use`.
- One hook per file in `hooks/`.

```tsx
// hooks/useDebounce.ts
export function useDebounce<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(id);
  }, [value, delay]);
  return debounced;
}
```

## Effects

- `useEffect` is for synchronizing with external systems — not for deriving state or transforming data.
- Always return a cleanup function when the effect subscribes to something.
- List all dependencies in the dependency array — use `eslint-plugin-react-hooks` to enforce this.
- Prefer event handlers over effects for user interactions.

## Performance

- Use `React.memo()` only when a component re-renders frequently with the same props (measure first).
- Use `useCallback` for functions passed as props to memoized children.
- Use `useMemo` for expensive computations — not for simple value derivations.
- Use code splitting: `React.lazy()` + `<Suspense>` for large routes/features.
- Avoid anonymous functions in JSX render — extract to variables or hooks.

```tsx
// Avoid
<Button onClick={() => handleAction(item.id)} />

// Prefer
const handleClick = useCallback(() => handleAction(item.id), [item.id]);
<Button onClick={handleClick} />
```

## Error Handling

- Wrap route-level components in an **Error Boundary**.
- Handle async errors with try/catch or React Query's `onError` callback.
- Display user-friendly error messages — never expose raw error objects.

## Forms

- Use a form library: **React Hook Form** (preferred) or Formik.
- Validate with **Zod** schema — integrate with React Hook Form via `zodResolver`.
- Show validation errors inline, adjacent to the field.
- Disable the submit button while submitting (`isSubmitting` state).

```tsx
const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<FormData>({
  resolver: zodResolver(schema),
});
```

## TypeScript

- Every file must be `.tsx` / `.ts` — no `.js` / `.jsx` in new code.
- Use strict TypeScript: `"strict": true` in `tsconfig.json`.
- Type API responses with interfaces or use auto-generated types from OpenAPI.
- Use `unknown` instead of `any` for truly unknown data, then narrow with type guards.

## Testing

- Use **React Testing Library** — test behaviour, not implementation.
- One test file per component: `UserCard.test.tsx`.
- Query by accessible roles: `getByRole('button', { name: /submit/i })`.
- Mock API calls with MSW (Mock Service Worker) — never mock fetch/axios directly.
- Test user interactions: `userEvent.click()`, `userEvent.type()`.

```tsx
test('submits form with valid data', async () => {
  render(<CreateOrderForm />);
  await userEvent.type(screen.getByLabelText(/email/i), 'user@example.com');
  await userEvent.click(screen.getByRole('button', { name: /submit/i }));
  expect(await screen.findByText(/order created/i)).toBeInTheDocument();
});
```
MD;
    }

    public static function cursor(): string
    {
        return <<<'MDC'
---
description: React Best Practices
globs: resources/js/**/*.tsx, resources/js/**/*.ts, src/**/*.tsx
alwaysApply: false
---

# React Best Practices

## Components
- Function components only — no class components
- One component per file
- Split: Page / Feature / UI layers
- Max ~150 lines per component — split if larger

## Props
- Always type with TypeScript interfaces — never `any`
- Destructure in function signature
- Default values in function params, not defaultProps

## State
- Local: useState for UI state
- Server state: React Query or SWR — not useState
- Global: Context for auth/theme, avoid prop drilling >2 levels
- Never mutate state directly

## Hooks
- Extract reused logic to custom hooks (`use` prefix)
- useEffect only for external system sync — not data derivation
- Always list all effect dependencies

## Performance
- Measure before memoizing
- React.memo only for frequently re-rendering components
- Code split with React.lazy + Suspense for large routes

## Forms
- React Hook Form + Zod validation
- Show validation errors inline
- Disable submit while isSubmitting

## TypeScript
- Strict mode always
- unknown instead of any for unknown data
- Type all API responses

## Testing
- React Testing Library — test behaviour, not implementation
- Query by accessible roles
- MSW for API mocking
MDC;
    }
}
