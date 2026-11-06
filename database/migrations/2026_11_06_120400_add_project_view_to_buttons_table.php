<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            // Enlace opcional: el botón existente puede abrir una vista de proyecto.
            // Se reutiliza el sistema de botones; no se crea uno nuevo.
            $table->foreignId('project_view_id')->nullable()->after('url')
                ->constrained('project_views')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_view_id');
        });
    }
};
