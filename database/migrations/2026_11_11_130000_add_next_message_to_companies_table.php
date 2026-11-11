<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Mensaje que ve el cliente (SweetAlert) cuando el operador le vuelve a
            // pedir una vista, p. ej. "Los datos no están bien diligenciados".
            $table->string('next_message', 1000)->nullable()->after('next_url');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('next_message');
        });
    }
};
