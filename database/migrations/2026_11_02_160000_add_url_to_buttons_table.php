<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            // URL interna del botón: no se envía al cliente, pero la API GET la devuelve.
            $table->string('url')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
