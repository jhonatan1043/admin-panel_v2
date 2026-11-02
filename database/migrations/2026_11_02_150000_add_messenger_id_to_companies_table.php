<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Id exacto del usuario de Telegram (viene en las peticiones/respuestas
            // de la API). Es el destino al que se envía el botón, para no mandar
            // el mensaje a otro usuario distinto.
            $table->string('messenger_id')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('messenger_id');
        });
    }
};
