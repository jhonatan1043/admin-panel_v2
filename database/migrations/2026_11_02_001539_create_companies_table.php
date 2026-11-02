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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            // Cada empresa se identifica por su "registro", no por un nombre comercial.
            $table->string('registro')->unique();
            // Credenciales del bot de Telegram por empresa.
            $table->string('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            // Imagen de espera configurable, distinta para cada registro.
            $table->string('loading_image_path')->nullable();
            // Estado operativo del registro en el panel.
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
