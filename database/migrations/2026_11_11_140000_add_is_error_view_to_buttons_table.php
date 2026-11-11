<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            // Botón "vista de error": reabre la misma vista mostrando el texto como
            // aviso de corrección (SweetAlert) para que el cliente corrija los datos.
            $table->boolean('is_error_view')->default(false)->after('project_view_id');
        });
    }

    public function down(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            $table->dropColumn('is_error_view');
        });
    }
};
