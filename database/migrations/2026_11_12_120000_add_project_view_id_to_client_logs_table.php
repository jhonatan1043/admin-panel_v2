<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            // Vista de proyecto asociada al registro: el botón con cronómetro guarda la
            // vista que pidió, y el envío del cliente guarda de qué vista vino. Así la
            // alarma empareja por VISTA (no por valor numérico).
            $table->unsignedBigInteger('project_view_id')->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            $table->dropColumn('project_view_id');
        });
    }
};
