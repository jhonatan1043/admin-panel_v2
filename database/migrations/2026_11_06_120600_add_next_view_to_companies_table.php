<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Siguiente vista que el operador pide para este cliente. La pantalla de
            // carga del cliente la consulta y navega hacia ella. Se limpia al consumirse.
            $table->foreignId('next_view_id')->nullable()->after('loading_token')
                ->constrained('project_views')->nullOnDelete();
            $table->timestamp('next_view_at')->nullable()->after('next_view_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_view_id');
            $table->dropColumn('next_view_at');
        });
    }
};
