<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // URL a la que el operador manda al cliente cuando el botón no abre una
            // vista del proyecto sino una dirección externa. La lee la pantalla de
            // carga/bundle en el siguiente sondeo y redirige allí.
            $table->string('next_url')->nullable()->after('next_view_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('next_url');
        });
    }
};
