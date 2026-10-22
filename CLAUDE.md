# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`aw-studio/laravel-states` is a Laravel package (namespace `AwStudio\States`, PSR-4 from `src/`) that adds the finite state pattern to Eloquent models. States are not columns on the model: every state change is appended as a row to a single polymorphic `states` table (`migrations/create_states_table.php`), so a model's full history is kept and new states need no migration. Usage docs for consumers are in `README.md`.

## Commands

```bash
composer install
composer test                                             # vendor/bin/phpunit
vendor/bin/phpunit tests/ModelIntegrationTest.php         # single file
vendor/bin/phpunit --filter it_executes_transition        # single test
```

Tests run on Orchestra Testbench against in-memory SQLite (`tests/TestCase.php`). Integration tests load the package migration and create their own fixture tables in `setUp()`; fixture models/state classes (`Booking`, `BookingState`, …) are declared at the bottom of the test files. Tests use the `/** @test */` + `it_…` naming style.

Code style is enforced by StyleCI (Laravel preset), not a local linter.

Version support: Laravel 8–13 on PHP 7.4–8.5, with PHPUnit 9–13 / Testbench 6–11 as dev deps. CI (`.github/workflows/tests.yml`) runs each Laravel major on its lowest and highest supported PHP. Because of that range:
- `src/` must stay PHP 7.4-compatible (no constructor promotion, enums, `readonly`, union types, etc.), but must also avoid PHP 8.4+ deprecations (e.g. use `?Type $x = null`, never implicit nullable).
- Tests use `test_` method prefixes (not `@test` doc-comments or attributes), and `phpunit.xml` stays minimal so it validates on PHPUnit 9 through 13.
- Package migrations are loaded in `defineDatabaseMigrations()`, not `setUp()`, otherwise older Testbench versions never create the `states` table.

## Architecture

The pieces and how they connect:

- **`HasStates` trait** (applied to a model implementing `Contracts\Stateful`) — the core. The model declares `protected $states = ['state' => BookingState::class, 'payment_state' => ...]`; each key is a state *type*.
  - `initializeHasStates()` dynamically registers a `current_{type}` belongsTo relation per type via `resolveRelationUsing` (guarded by a static `$initialized` per class).
  - Accessing `$model->{type}` is intercepted by overriding `hasGetMutator`/`mutateAttribute`, returning a fresh `State` subclass instance bound to the model and type (a user-defined accessor for the same key still runs on top).
  - `getState($type)` lazily loads the latest `states` row (highest `id`) for that type; if none exists it returns the class's `INITIAL_STATE`. **The initial state is never persisted** — "is initial" means "has no rows of that type". All query scopes (`whereStateIs`, `whereStateIsNot`, `whereStateWas`, …) special-case `INITIAL_STATE` accordingly, and "current" is determined by a `NOT EXISTS` subquery for a newer row with greater `id`.
  - `registerObserver()` is overridden to reflect on observer methods and register custom model events by naming convention: `{type}{State}` (e.g. `paymentStatePaid`), `{type}Transition{Transition}`, `changed{Type}`, and `{type}{A}Or{B}`. `fireStateEventsFor()` fires these after a transition.
- **`State` (abstract)** — user subclasses define states as class constants plus `INITIAL_STATE`/`FINAL_STATES`; `all()` reflects over constants (excluding those two), so *every other constant on a state class is treated as a state*. Transitions are declared in static `config()` via `self::set($name)->from(..)->to(..)` and cached in a static `$transitions[static::class]` map on first `getTransitions()` call. Instance methods (`current`, `is`, `was`, `can`, `transition`, `lockForUpdate`, `reload`) operate on the bound model. `transition()` runs in a `DB::transaction` (5 attempts), reloads + locks the state rows, validates, inserts a new row via `States::makeFromTransition`, sets the `current_{type}` relation, then fires observer events.
- **`States`** — a `MorphMany` subclass returned by `$model->states($type = null)`, adding `makeFromTransition()`.
- **`Models\State`** — the Eloquent model for the `states` table (`type`, `state`, `from`, `transition`, `reason`, `stateful_*` morphs).
- **`Transition`** — plain value object (`name`, `from`, `to`).
- **`StatesServiceProvider`** — only publishes the migration under the `states:migrations` tag.

Queries use `$this->getMorphClass()` for `stateful_type`, so morph maps are respected; keep that when adding scopes.
