<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('module_id')
                ->constrained('modules')
                ->cascadeOnDelete();

            // Machine-readable identifier, e.g. 'view', 'create', 'export',
            // 'void'. Scoped unique per module — 'export' can exist under
            // both 'reports' and 'sales' without colliding.
            $table->string('key');

            // Human-readable name shown in the permission-matrix UI,
            // e.g. 'Export Report', 'Void Transaction'.
            $table->string('label');

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['module_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_actions');
    }
};