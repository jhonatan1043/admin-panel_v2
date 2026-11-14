<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            // Lista de archivos asociados a la vista (CSS/JS/imágenes/fuentes que se
            // incrustaron o fusionaron), para mostrarla en el detalle de la vista.
            $table->json('assets')->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            $table->dropColumn('assets');
        });
    }
};
