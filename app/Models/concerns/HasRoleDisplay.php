<?php

namespace App\Models\Concerns;

/**
 * Lets the generic <livewire:data-tables /> component display each user's
 * Spatie role as a badge without any special-casing in that shared component.
 *
 * data-tables builds its query as `$modelClass::query()->...->paginate()`
 * and reads columns via `data_get($row, $col['key'])`. Since roles live on
 * Spatie's separate `model_has_roles` pivot (not a `users` column), we:
 *
 *   1. Always eager-load `roles` via a global scope, so the table's
 *      untouched query picks it up with zero N+1 risk.
 *   2. Expose `role_label` / `role_color` as accessors so they behave like
 *      plain attributes data_get() can read, and so formatCell()'s
 *      'badge:role_color=role_label' format works unmodified.
 *
 * Apply by adding `use HasRoleDisplay;` on the User model, alongside Spatie's
 * own `HasRoles` trait (this trait does not replace it).
 */
trait HasRoleDisplay
{
    protected static function bootHasRoleDisplay(): void
    {
        static::addGlobalScope('withRoleNames', function ($query) {
            $query->with('roles:id,name');
        });
    }

    /**
     * First assigned role name, title-cased for display.
     * Single-role-per-user per the modal's select field — if a user somehow
     * ends up with multiple Spatie roles, only the first is shown here.
     */
    public function getRoleLabelAttribute(): string
    {
        $name = $this->roles->first()?->name;

        return $name ? ucfirst($name) : 'No role';
    }

    /**
     * Drives the badge color in formatCell()'s colorMap (active/inactive/
     * pending/cancelled/paid/unpaid). Map your real role names to whichever
     * of those keys gives the color you want; anything unmapped falls back
     * to data-tables' default gray pill.
     *
     * match() against the raw role name is exact-match/case-sensitive —
     * a role actually stored as 'Admin' (capital A) wouldn't hit the
     * 'admin' arm below and would silently fall through to 'inactive'
     * (gray), same root cause as the hasRole('admin') bug fixed in
     * HasModuleAccess::isAdmin(). Lowercasing before the match avoids it.
     */
    public function getRoleColorAttribute(): string
    {
        return match (strtolower((string) $this->roles->first()?->name)) {
            'admin'   => 'active',    // green
            'manager' => 'paid',      // blue
            'cashier' => 'pending',   // yellow
            default   => 'inactive',  // gray
        };
    }
}