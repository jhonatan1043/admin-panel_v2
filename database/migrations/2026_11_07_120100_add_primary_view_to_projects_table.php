<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Vista principal (index) del proyecto: la que se sirve al entrar por la raíz.
            // Null = se usa la primera vista de formulario como respaldo.
            $table->foreignId('primary_view_id')->nullable()->after('status')
                ->constrained('project_views')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_view_id');
        });
    }
};
