<?php

namespace App\Models\Concerns;

/**
 * Lets the generic <livewire:data-tables /> component display each role's
 * assigned-user count (for the 'users_count' column / delete-guard toast)
 * without any special-casing in that shared component.
 *
 * data-tables builds its query as `$modelClass::query()->...->paginate()`
 * with no way to inject a withCount() call per-page. So instead we apply
 * the count globally:
 *
 *   1. Always eager-count `users` via a global scope, so the table's
 *      untouched query picks up `users_count` with zero extra wiring.
 *   2. data_get($row, 'users_count') in formatCell() then "just works" as
 *      if it were a plain column, letting the 'integer' format render it.
 *
 * Apply by adding `use HasRoleUserCount;` on App\Models\Role, alongside
 * Spatie's own role behavior (App\Models\Role should extend Spatie's base
 * Role class — this trait does not replace it).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 *
 * The @mixin hint above is for static analyzers / IDEs only — it tells them
 * this trait is always composed into an Eloquent Model subclass, so the
 * inherited addGlobalScope() (and other Model statics/methods) actually
 * exist at runtime even though this file, in isolation, has no `extends`.
 * Without it, tools like Intelephense flag addGlobalScope() as undefined
 * since traits have no base class of their own to resolve it from.
 */
trait HasRoleUserCount
{
    protected static function bootHasRoleUserCount(): void
    {
        static::addGlobalScope('withUsersCount', function ($query) {
            $query->withCount('users');
        });
    }
}