<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            // Al presionarlo, además de su acción normal, la tarjeta pasa a "desconectado".
            $table->boolean('moves_offline')->default(false)->after('is_error_view');
        });
    }

    public function down(): void
    {
        Schema::table('buttons', function (Blueprint $table) {
            $table->dropColumn('moves_offline');
        });
    }
};
