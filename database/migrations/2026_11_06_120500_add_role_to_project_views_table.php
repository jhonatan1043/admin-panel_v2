<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            // 'standard' = vista de formulario; 'loading' = pantalla de espera del proyecto.
            $table->string('role')->default('standard')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
