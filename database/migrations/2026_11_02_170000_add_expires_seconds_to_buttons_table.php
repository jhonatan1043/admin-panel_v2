<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            // Vencimiento (en segundos) de la opción. Nulo = el botón no vence.
            // El conteo arranca cuando llega el valor/mensaje del cliente.
            $table->unsignedInteger('expires_seconds')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            $table->dropColumn('expires_seconds');
        });
    }
};
