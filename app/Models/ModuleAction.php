<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class ModuleAction extends Model
{
    protected $table = 'module_actions';

    protected $fillable = [
        'module_id',
        'key',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'module_id'  => 'integer',
        'sort_order' => 'integer',
    ];

    public function module()
    {
        return $this->belongsTo(Modules::class, 'module_id');
    }

    /**
     * Roles granted this action, via the module_permissions pivot.
     * Existence of a row = granted; no row = denied (default-deny).
     */
    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'module_permissions',
            'module_action_id',
            'role_id'
        )->withTimestamps();
    }

    public function scopeForModule($query, int $moduleId)
    {
        return $query->where('module_id', $moduleId);
    }
}