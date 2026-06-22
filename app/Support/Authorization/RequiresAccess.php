<?php

namespace App\Support\Authorization;

use Attribute;

/**
 * Declares that a Livewire/Volt component method requires the current user
 * to have a specific module_action permission before it's allowed to run.
 *
 * Usage (on any public method of a component using EnforcesModuleAccess):
 *
 *   #[RequiresAccess('edit')]
 *   public function saveUser(array $data): void { ... }
 *
 *   #[RequiresAccess('delete')]
 *   public function deleteUser(int $id): void { ... }
 *
 * The module slug is NOT passed here — it's read from the component's own
 * `protected string $moduleSlug` property (see EnforcesModuleAccess), since
 * a single component almost always checks against one module throughout.
 * If a component genuinely needs to check a different module slug for one
 * specific method, pass $moduleSlug explicitly to override:
 *
 *   #[RequiresAccess('edit', moduleSlug: 'some-other-module')]
 *
 * Checked automatically by EnforcesModuleAccess::boot() before the method
 * body runs — see that trait for the enforcement mechanism. A method with
 * no #[RequiresAccess] attribute is NOT checked at all (opt-in, not
 * opt-out) — lifecycle/internal methods (mount, with, updatedX, etc.)
 * should be left unannotated.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresAccess
{
    public function __construct(
        public readonly string $action,
        public readonly ?string $moduleSlug = null,
    ) {
    }
}