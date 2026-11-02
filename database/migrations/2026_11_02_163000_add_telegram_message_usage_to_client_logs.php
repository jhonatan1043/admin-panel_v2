<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('detail');
            $table->timestamp('used_at')->nullable()->after('telegram_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_logs', function (Blueprint $table) {
            $table->dropColumn(['telegram_message_id', 'used_at']);
        });
    }
};
