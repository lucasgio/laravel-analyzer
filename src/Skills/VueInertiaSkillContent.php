<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Skills;

class VueInertiaSkillContent
{
    public static function claude(): string
    {
        return <<<'MD'
# Vue.js + Inertia.js Best Practices

Apply the following standards when writing Vue components and Inertia.js pages in this project.

---

## Project Structure

```
resources/js/
├── pages/           # Inertia page components (one per Laravel route)
├── components/
│   ├── ui/          # Generic: Button, Input, Modal, Badge (no business logic)
│   └── [Feature]/   # Domain components: OrderTable, UserForm
├── composables/     # Reusable logic (useAuth, useFilters, usePagination)
├── layouts/         # App layout, guest layout, auth layout
└── types/           # TypeScript interfaces for all Inertia shared data and props
```

## Composition API — Always

- Use `<script setup>` for all components — it is more concise and has better TypeScript support.
- Never use the Options API in new code.
- Declare props with `defineProps<{...}>()`, emits with `defineEmits<{...}>()`.

```vue
<script setup lang="ts">
import type { Order } from '@/types'

const props = defineProps<{
  order: Order
  editable?: boolean
}>()

const emit = defineEmits<{
  updated: [order: Order]
}>()
</script>
```

## Composables

- Extract all reusable logic into composables: `useAuth`, `usePagination`, `useFilters`, `useToast`.
- Composables must live in `composables/` and be prefixed with `use`.
- A composable returns reactive state and functions — nothing else.

```ts
// composables/useFilters.ts
export function useFilters(initial: Record<string, string> = {}) {
  const filters = reactive({ ...initial })

  const apply = () => router.get(route('orders.index'), filters, { preserveState: true })
  const reset = () => { Object.assign(filters, initial); apply() }

  return { filters, apply, reset }
}
```

## Inertia.js — Page Components

- One page component per route. Page components live in `pages/`.
- Page components receive **props from the server** — declare them all in `defineProps`.
- Never fetch data on the frontend if it can be passed via Inertia's `share()` or as a prop.
- Use `usePage()` to access shared data (auth user, flash messages).

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'

const page = usePage<PageProps>()
const auth = computed(() => page.props.auth)
</script>
```

## Inertia Forms

- Always use `useForm()` from `@inertiajs/vue3` for form handling.
- Never use `fetch()` or `axios` directly for form submissions that navigate.
- Handle errors through `form.errors`, not a separate error state.

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

const form = useForm({
  name: '',
  email: '',
  role: 'user',
})

function submit() {
  form.post(route('users.store'), {
    onSuccess: () => form.reset(),
  })
}
</script>

<template>
  <form @submit.prevent="submit">
    <input v-model="form.name" />
    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>

    <button type="submit" :disabled="form.processing">Save</button>
  </form>
</template>
```

## Navigation & Links

- Use `<Link>` from `@inertiajs/vue3` instead of `<a>` for internal navigation.
- Use `router.visit()` for programmatic navigation: `router.visit(route('orders.show', order.id))`.
- Preserve state on filter/sort changes: `router.get(url, params, { preserveState: true, replace: true })`.

## Shared Data (Server → Client)

- Pass global data via `HandleInertiaRequests::share()` in the middleware.
- Type shared data in `types/index.ts`:

```ts
export interface PageProps {
  auth: {
    user: User | null
  }
  flash: {
    success?: string
    error?: string
  }
}
```

- Access with `usePage<PageProps>().props.flash.success`.

## Flash Messages

- Server sets flash in session: `session()->flash('success', 'Order created.')`.
- Client reads from shared props and displays with a toast composable.
- Always clear flash after displaying it (Inertia does this automatically on next navigation).

## Reactivity

- Use `ref()` for primitives, `reactive()` for objects/arrays.
- Use `computed()` for derived values — never duplicate state.
- Avoid watchers (`watch`, `watchEffect`) when a `computed` can replace them.
- Deep watch only when necessary: `watch(source, handler, { deep: true })`.

## TypeScript

- Enable strict TypeScript in `tsconfig.json`.
- Type all props, emits, composable return values, and API responses.
- Never use `any` — use `unknown` and narrow with type guards.
- Auto-generate route types with `ziggy-js` + route typings if used.

## Performance

- Use `defineAsyncComponent()` for heavy components not needed on initial load.
- Use `v-memo` for lists with rarely changing items.
- Paginate all server-side lists — never fetch all records.
- Use `shallowRef()` for large non-reactive objects.

## Testing

- Use **Vitest** + **Vue Test Utils** for unit tests.
- Use **Cypress** or **Playwright** for end-to-end tests.
- Test composables independently with `renderComposable()` or plain function calls.
- Mock `usePage()` when testing page components.

```ts
// composables/useFilters.test.ts
import { useFilters } from './useFilters'

test('apply sends filters to the server', () => {
  const { filters, apply } = useFilters({ status: 'active' })
  filters.status = 'inactive'
  // assert router.get was called with correct params
})
```
MD;
    }

    public static function cursor(): string
    {
        return <<<'MDC'
---
description: Vue.js + Inertia.js Best Practices
globs: resources/js/**/*.vue, resources/js/**/*.ts
alwaysApply: false
---

# Vue.js + Inertia.js Best Practices

## Components
- Always use `<script setup lang="ts">` — Composition API only
- One component per file: pages/, components/ui/, components/[Feature]/
- Props: `defineProps<{...}>()` — always typed, never `any`
- Emits: `defineEmits<{...}>()` — always typed

## Composables
- All reusable logic in composables/ with `use` prefix
- Return reactive state and functions only
- Prefer computed over watch for derived values

## Inertia Forms
- Always use `useForm()` from @inertiajs/vue3
- Never use fetch/axios for form submissions
- Handle errors via `form.errors`, not separate state
- Use `form.processing` to disable submit button

## Navigation
- `<Link>` for internal navigation, not `<a>`
- `router.visit()` for programmatic nav
- `preserveState: true` for filter/sort changes

## Shared Data
- Type all shared data in types/index.ts as PageProps
- Access via `usePage<PageProps>().props`
- Flash messages from shared props, not separate API calls

## Reactivity
- `ref()` for primitives, `reactive()` for objects
- `computed()` for derived values — avoid duplicating state
- Avoid deep watchers unless strictly necessary

## Performance
- `defineAsyncComponent()` for heavy components
- Paginate all lists server-side
- Never fetch all records at once

## TypeScript
- Strict mode always
- Type all composable return values
- unknown over any for unknown data
MDC;
    }
}
