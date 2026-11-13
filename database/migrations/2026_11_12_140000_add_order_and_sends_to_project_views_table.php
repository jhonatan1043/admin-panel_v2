<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            // Orden de la vista dentro del proyecto (secuencia por la que pasa el cliente).
            $table->integer('sort_order')->default(0)->after('slug');
            // ¿La vista envía información (a la base y a Telegram)? Si es false es una
            // vista "de paso": el cliente avanza solo a la siguiente del orden, sin enviar.
            $table->boolean('sends_data')->default(true)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('project_views', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'sends_data']);
        });
    }
};
