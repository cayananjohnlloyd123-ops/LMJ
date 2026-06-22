<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_permissions', function (Blueprint $table) {
            $table->id();

            // References Spatie's roles table directly. Spatie's role ids
            // are standard auto-increment bigints by default, matching
            // foreignId() here. If your install customized Spatie's role
            // key type, adjust this column to match.
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->foreignId('module_action_id')
                ->constrained('module_actions')
                ->cascadeOnDelete();

            $table->timestamps();

            // A role either has a given module_action granted or it
            // doesn't — one row per pair. Existence = granted (default-deny:
            // no row means no access). Enforced at the app layer too, but
            // the unique constraint prevents duplicate grant rows.
            $table->unique(['role_id', 'module_action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_permissions');
    }
};