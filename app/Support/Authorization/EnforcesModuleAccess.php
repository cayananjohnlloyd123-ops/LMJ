<?php

namespace App\Support\Authorization;

use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * `use EnforcesModuleAccess;` on a Volt/Livewire component, combined with
 * #[RequiresAccess('action')] attributes on its methods, gives you a
 * one-line permission check instead of repeating abort_unless(...) +
 * canAccess(...) boilerplate in every protected method.
 *
 * USAGE:
 *
 *   new class extends Component {
 *       use EnforcesModuleAccess;
 *
 *       protected string $moduleSlug = 'users';
 *
 *       #[RequiresAccess('edit')]
 *       public function saveUser(array $data): void
 *       {
 *           $this->guard(__FUNCTION__);
 *           // ... rest of the method, only reached if authorized ...
 *       }
 *   }
 *
 * WHY THIS ISN'T FULLY AUTOMATIC (and why guard() is still required):
 * Livewire 3.6's component-hook / global-listener APIs for intercepting an
 * action BEFORE its method body runs are either JS-only (interceptAction,
 * Livewire 4+) or have open reliability issues as of this Livewire version
 * (see livewire/livewire discussions #8996 and #8773 — ComponentHook's
 * hydrate() not firing reliably, Livewire.on() missing dispatches from
 * several lifecycle hooks). Building silent, security-relevant enforcement
 * on top of admittedly-flaky undocumented internals would be worse than
 * being explicit. So enforcement happens via one self-documenting line
 * (guard()) at the top of each method that needs it, backed by a single
 * source of truth (the #[RequiresAccess] attribute) so the action key
 * itself only has to be declared once, not duplicated between an attribute
 * and a manual check.
 *
 * guard() with no #[RequiresAccess] on the calling method is a no-op that
 * also THROWS in non-production environments (see guard() below) — this
 * surfaces "I called guard() but forgot the attribute" immediately during
 * development rather than silently allowing everything through.
 */
trait EnforcesModuleAccess
{
    /**
     * Method name => RequiresAccess instance, built once per request from
     * #[RequiresAccess] attributes via Reflection. Populated in boot()
     * (runs on every request, per Livewire's lifecycle), not mount()
     * (initial load only), so it's correctly rebuilt on every subsequent
     * component hydration too.
     */
    protected array $requiresAccessMap = [];

    public function bootEnforcesModuleAccess(): void
    {
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(RequiresAccess::class);

            if (empty($attributes)) {
                continue;
            }

            /** @var RequiresAccess $requirement */
            $requirement = $attributes[0]->newInstance();

            $this->requiresAccessMap[$method->getName()] = $requirement;
        }
    }

    /**
     * Call as the first line of any method carrying #[RequiresAccess(...)]:
     *
     *   #[RequiresAccess('delete')]
     *   public function deleteUser(int $id): void
     *   {
     *       $this->guard(__FUNCTION__);
     *       ...
     *   }
     *
     * Aborts with 403 if the current user lacks the required permission.
     * Returns void (not bool) — it's a guard clause, not a conditional;
     * callers aren't meant to branch on its result, only call it and
     * continue if it didn't throw.
     */
    protected function guard(string $methodName): void
    {
        $requirement = $this->requiresAccessMap[$methodName] ?? null;

        if ($requirement === null) {
            // guard() called from a method with no #[RequiresAccess]
            // attribute — almost certainly a mistake (attribute forgotten
            // or removed but the guard() call left behind). Fail loud in
            // non-production so it's caught in development; in production,
            // fail safe by denying rather than crashing the request for
            // end users — but this should never actually be reached there
            // if caught earlier in dev/staging.
            if (! app()->isProduction()) {
                throw new \LogicException(sprintf(
                    '%s::guard(\'%s\') was called, but %s::%s() has no #[RequiresAccess] attribute. '.
                    'Add the attribute, or remove the guard() call if this method should be unrestricted.',
                    static::class,
                    $methodName,
                    static::class,
                    $methodName,
                ));
            }

            throw new HttpException(403, "You don't have access to perform this action.");
        }

        $moduleSlug = $requirement->moduleSlug ?? ($this->moduleSlug ?? null);

        if ($moduleSlug === null) {
            throw new \LogicException(sprintf(
                '%s::%s() has #[RequiresAccess] but no moduleSlug is available — '.
                'either declare protected string $moduleSlug on the component, '.
                'or pass moduleSlug explicitly: #[RequiresAccess(\'%s\', moduleSlug: \'...\')].',
                static::class,
                $methodName,
                $requirement->action,
            ));
        }

        $user = auth()->user();

        if (! $user || ! $user->canAccess($moduleSlug, $requirement->action)) {
            throw new HttpException(403, "You don't have access to perform this action.");
        }
    }

    /**
     * Convenience for Blade: `@if ($this->can('edit'))` to conditionally
     * show buttons/sections, using the same moduleSlug the component
     * already declares — no need to separately compute $canEdit-style
     * public properties in mount() purely for UI visibility anymore.
     *
     * NOTE: this is a UI-visibility helper, NOT a substitute for guard()
     * inside the actual action methods — see EnforcesModuleAccess's
     * docblock. A hidden button doesn't stop a direct Livewire call.
     */
    public function can(string $action, ?string $moduleSlug = null): bool
    {
        $moduleSlug ??= $this->moduleSlug ?? null;

        if ($moduleSlug === null) {
            throw new \LogicException(sprintf(
                '%s::can(\'%s\') was called with no moduleSlug available — '.
                'declare protected string $moduleSlug on the component, or pass it explicitly.',
                static::class,
                $action,
            ));
        }

        $user = auth()->user();

        return $user !== null && $user->canAccess($moduleSlug, $action);
    }
}