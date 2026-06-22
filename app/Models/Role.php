<?php

namespace App\Models;

use App\Models\Concerns\HasRoleUserCount;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * App-level Role model, used wherever the app needs the Spatie role plus the
 * always-on users_count (see HasRoleUserCount). Spatie's package config
 * ('permission.php' -> 'models.role') should point to this class instead of
 * Spatie\Permission\Models\Role directly, so anywhere the package itself
 * resolves a Role (e.g. via the `roles` relation on User) also benefits.
 */
class Role extends SpatieRole
{
    use HasRoleUserCount;
}