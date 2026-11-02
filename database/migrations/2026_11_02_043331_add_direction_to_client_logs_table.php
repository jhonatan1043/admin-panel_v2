<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            // 'out' = enviado desde el panel; 'in' = respuesta del cliente por Telegram.
            $table->string('direction')->default('out')->after('operator_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
