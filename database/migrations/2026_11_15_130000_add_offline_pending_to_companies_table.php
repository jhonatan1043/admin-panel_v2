<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Un botón con "pasa a desconectado" se presionó mientras el cliente seguía en
            // línea: la tarjeta pasa a desconectado apenas deje de estarlo.
            $table->boolean('offline_pending')->default(false)->after('code_attended_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('offline_pending');
        });
    }
};
