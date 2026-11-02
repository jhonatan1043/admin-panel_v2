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
        Schema::create('client_logs', function (Blueprint $table) {
            $table->id();
            // Auditoría de cada paso enviado al cliente.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();   // botón pulsado
            $table->text('text');                  // texto enviado
            $table->string('status');              // sent | failed
            $table->text('detail')->nullable();    // respuesta/descripción de Telegram o motivo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_logs');
    }
};
