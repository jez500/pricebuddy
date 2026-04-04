# Workspace Tenancy Data Model and Migration Plan

## Scope

This document defines a `workspace_id` tenancy boundary for the current Laravel 12 + Filament + Sanctum codebase, identifies entities that must be tenant-scoped, and proposes a migration sequence that preserves current self-hosted single-user and multi-user behavior.

## Current ownership model (as implemented)

The codebase currently uses `user_id` as the practical data boundary for most app data.

### Directly user-owned entities

- `products` (`user_id` required)
- `stores` (`user_id` nullable)
- `tags` (`user_id` nullable)
- `product_sources` (`user_id` nullable)

### Indirectly owned entities

- `urls` (owned via `product_id` / `store_id`)
- `prices` (owned via `url_id` / `store_id`)
- `taggables` (ownership implied by `tags` and `products`)

### Global or system entities

- `users`
- `personal_access_tokens` (Sanctum tokenable polymorph)
- `notifications` (notifiable polymorph)
- `settings` (global key/value)
- `url_research` (currently not user/workspace scoped)
- infra tables (`jobs`, `failed_jobs`, `cache`, `sessions`, `log_messages`)

## Gaps and risks in current model

- Tenant boundary is implicit and spread across many query call sites (`where('user_id', auth()->id())`), increasing leak risk when a new query path misses the filter.
- Several ownership columns are nullable (`stores.user_id`, `tags.user_id`, `product_sources.user_id`), allowing orphan/global-like rows with ambiguous visibility.
- `product_sources.slug` is globally unique today, which blocks same slug reuse across tenants.
- `url_research` is unscoped and can mix tenant search artifacts.
- Background jobs/events do not carry explicit tenant context.
- Database constraints are weak for ownership consistency (ownership is mostly enforced by application code, not relational constraints).

## Proposed tenancy model

Use **workspace** as the tenancy boundary.

### New core tables

- `workspaces`
  - `id`, `name`, `slug`, `owner_user_id`, timestamps
- `workspace_user`
  - `workspace_id`, `user_id`, `role`, timestamps
  - unique index on (`workspace_id`, `user_id`)

### Tenant-scoped app tables (add `workspace_id`)

- `products`
- `stores`
- `tags`
- `product_sources`
- `urls`
- `prices`
- `url_research`

Keep `user_id` where needed for creator/audit semantics, but stop using it as the primary tenancy filter.

### Uniqueness and indexing (post-migration target)

- `stores`: unique (`workspace_id`, `slug`)
- `tags`: unique (`workspace_id`, `name`)
- `product_sources`: unique (`workspace_id`, `slug`) (replace global unique slug)
- `urls`: index (`workspace_id`, `product_id`), optional uniqueness rules per product/workspace
- `prices`: index (`workspace_id`, `url_id`, `created_at`)

## Migration sequencing

## Phase 0: foundation

- Add `workspaces` and `workspace_user` tables.
- Create one default workspace per existing user (e.g. "<name>'s workspace").
- Add `current_workspace_id` to users (or user settings) for context selection.

## Phase 1: additive schema + backfill

- Add nullable `workspace_id` to tenant tables listed above.
- Backfill strategy:
  - `products/stores/tags/product_sources`: map from `user_id` to user's default workspace.
  - `urls`: inherit from related `products.workspace_id` (with consistency check against `stores.workspace_id`).
  - `prices`: inherit from related `urls.workspace_id`.
  - `url_research`: if source user not known, map to workspace via `store_id` where possible; otherwise assign a dedicated system workspace for legacy rows.
- Add non-unique indexes on `workspace_id` columns to support dual-read period.

## Phase 2: dual-read, then workspace-first read/write

- Introduce a workspace context resolver (middleware/service) used by Filament pages, API handlers, and jobs.
- Replace direct `auth()->id()` ownership filters with `workspace_id = currentWorkspaceId`.
- On create/update flows, dual-write `workspace_id` and legacy `user_id` for one release window.
- Add policy checks on workspace membership/role.

## Phase 3: constraints and cleanup

- Make `workspace_id` non-null on tenant tables.
- Add FK constraints to `workspaces(id)`.
- Convert global uniques to workspace-scoped uniques (notably `product_sources.slug`).
- Remove tenancy logic tied to `user_id` (keep as creator metadata if needed).

## Compatibility strategy

### Self-hosted single-user

- Auto-create one workspace and auto-select it.
- UI/API behavior stays effectively unchanged because all records resolve to the same workspace.

### Current multi-user behavior

- Preserve current isolation by mapping each user's records into their own default workspace during backfill.
- Existing login/token flows remain valid because identity stays on `users` and Sanctum tokenable user model.

### Shared-store behavior decision

`stores.user_id` being nullable suggests partial shared-store intent. Decide one of:

- Workspace-owned stores only (duplicate per workspace when needed), or
- Split into global catalog store definitions + workspace store overrides.

This choice should be finalized before Phase 3 constraints.

## Implementation checkpoints for follow-on tickets

- Introduce `Workspace` and `WorkspaceMembership` models.
- Add `BelongsToWorkspace` trait + global scope helper.
- Update Filament resources and API handlers to workspace scope.
- Propagate workspace context through jobs/events/commands.
- Add regression tests for cross-workspace access denial and single-workspace default behavior.

## Recommended next execution order

1. Implement workspace foundation tables + membership model.
2. Add additive `workspace_id` columns and backfill command/migration.
3. Switch `products/stores/tags/product_sources` query scope to workspace.
4. Switch `urls/prices/url_research` to workspace and enforce FK/unique constraints.
