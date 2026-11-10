<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Última vez que el navegador del cliente avisó presencia (heartbeat del index.js).
            // Sirve para el estado "en línea" en vivo mientras tiene la página abierta.
            $table->timestamp('last_seen_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
