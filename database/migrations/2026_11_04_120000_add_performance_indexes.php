<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices para las consultas que más se repiten con tráfico alto.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Tablero y GET /api/clients ordenan por actividad.
            $table->index('last_activity_at');
            // El webhook de Telegram busca al cliente por estos dos campos en cada mensaje.
            $table->index('messenger_id');
            $table->index('telegram_chat_id');
        });

        Schema::table('client_logs', function (Blueprint $table) {
            // Conversación de cada tarjeta: logs de un cliente, más recientes primero.
            $table->index(['company_id', 'created_at', 'id'], 'client_logs_company_recent_index');
        });
    }

    public function down(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            $table->dropIndex('client_logs_company_recent_index');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['last_activity_at']);
            $table->dropIndex(['messenger_id']);
            $table->dropIndex(['telegram_chat_id']);
        });
    }
};
