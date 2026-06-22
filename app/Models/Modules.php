<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Modules extends Model
{
    use HasFactory;

    protected $table = 'modules';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'icon',
        'route',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'parent_id'  => 'integer',
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    /**
     * Parent Module
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child Modules
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order');
    }

    /**
     * Active Child Modules
     */
    public function activeChildren()
    {
        return $this->hasMany(self::class, 'parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Actions defined for this module (view/create/edit/delete/export/...),
     * each grantable to roles via module_permissions. See ModuleAction.
     */
    public function actions()
    {
        return $this->hasMany(ModuleAction::class, 'module_id')
            ->orderBy('sort_order');
    }

    /**
     * Scope Active
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope Parent Modules
     */
    public function scopeParents($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope Menu Modules
     */
    public function scopeMenu($query)
    {
        return $query->where('show_in_menu', true);
    }
}