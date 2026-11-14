<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            // Ruta de origen de la vista cuando se importó de una carpeta (p. ej.
            // "sitio/pages/detalle.html"), para mostrar de qué carpeta/subcarpeta salió.
            $table->string('source_path')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            $table->dropColumn('source_path');
        });
    }
};
