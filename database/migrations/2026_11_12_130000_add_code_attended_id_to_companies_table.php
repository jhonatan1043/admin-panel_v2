<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Id del último valor de código marcado como "atendido": los códigos con id
            // igual o menor ya no ponen la tarjeta morada ni suenan; uno nuevo (id mayor) sí.
            $table->unsignedBigInteger('code_attended_id')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('code_attended_id');
        });
    }
};
