<?php

namespace App\Models\Concerns;

use App\Models\ModuleAction;
use App\Models\Modules;
use Illuminate\Support\Facades\Cache;

/**
 * Adds module/action-level permission checks to the User model, backed by
 * the module_actions + module_permissions tables (see those migrations).
 *
 * Rules (per product decision):
 *   - 'admin' role always passes every check — never needs a
 *     module_permissions row, and is never blocked by one being absent.
 *   - Every other role is DEFAULT-DENY: if no module_permissions row exists
 *     granting role X access to a given module_action, X is denied.
 *   - A user can hold multiple Spatie roles; access is granted if ANY of
 *     the user's roles has the permission.
 *
 * Apply by adding `use HasModuleAccess;` on the User model, alongside
 * Spatie's HasRoles trait (HasModuleAccess builds on top of getRoleNames(),
 * it doesn't replace anything Spatie provides).
 */
trait HasModuleAccess
{
    /**
     * Whether this user holds the 'admin' role, matched case-insensitively.
     *
     * Spatie's hasRole('admin') is case-sensitive — if the role was created
     * as 'Admin' (capital A, as Spatie\Permission\Models\Role::name stores
     * it verbatim however it was seeded/typed), hasRole('admin') silently
     * returns false and the admin bypass below never fires, even for an
     * actual admin. Comparing role names lowercased avoids that footgun
     * without requiring the 'admin' role to be re-seeded/renamed in the DB.
     */
    protected function isAdmin(): bool
    {
        return $this->roles->contains(
            fn ($role) => strtolower($role->name) === 'admin'
        );
    }

    /**
     * Whether this user can perform $actionKey on the module identified by
     * $moduleSlug. E.g. $user->canAccess('sales', 'void').
     *
     * Looks the module up by slug, then the action by key under that
     * module, then checks module_permissions for an entry matching any of
     * the user's roles. Returns false (not an exception) if the module or
     * action doesn't exist, so callers can use this directly in @if/middleware
     * without first checking existence themselves.
     */
    public function canAccess(string $moduleSlug, string $actionKey): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $module = Modules::where('slug', $moduleSlug)->first();

        if (! $module) {
            return false;
        }

        $action = ModuleAction::where('module_id', $module->id)
            ->where('key', $actionKey)
            ->first();

        if (! $action) {
            return false;
        }

        return $this->canPerform($action);
    }

    /**
     * Same check as canAccess(), but takes a ModuleAction instance directly
     * — useful when the caller already has it loaded (e.g. building a
     * permission-matrix UI) and wants to avoid two extra lookup queries.
     */
    public function canPerform(ModuleAction $action): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $roleIds = $this->roles->pluck('id');

        if ($roleIds->isEmpty()) {
            return false;
        }

        // Cached per-request (not cross-request) since a single request can
        // easily call canAccess()/canPerform() many times (e.g. rendering a
        // menu with a dozen module links) — this avoids N+1 queries against
        // module_permissions without introducing cross-request staleness
        // concerns. Keyed per user+action so it's safe across users sharing
        // a worker process (e.g. queue workers, octane).
        $cacheKey = "module-access:{$this->id}:{$action->id}";

        return once(fn () => $action->roles()
            ->whereIn('role_id', $roleIds)
            ->exists()
        );
    }

    /**
     * Whether this user can access the module at all — i.e. has at least
     * one granted action under it. Useful for menu visibility: a role might
     * not have every action on 'sales', but if it has ANY action, the menu
     * item should still show.
     */
    public function canAccessModule(string $moduleSlug): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $module = Modules::where('slug', $moduleSlug)->first();

        if (! $module) {
            return false;
        }

        $roleIds = $this->roles->pluck('id');

        if ($roleIds->isEmpty()) {
            return false;
        }

        return ModuleAction::where('module_id', $module->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('role_id', $roleIds))
            ->exists();
    }
}