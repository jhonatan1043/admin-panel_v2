<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            // La plantilla de la empresa, conservada íntegra (HTML + CSS + JS propios).
            $table->longText('html');
            $table->longText('css')->nullable();
            $table->longText('js')->nullable();
            // JavaScript de integración generado automáticamente (archivo aparte).
            $table->longText('integration_js')->nullable();
            // Resultado del analizador y especificación de integración (editable).
            $table->json('analysis')->nullable();
            $table->json('spec')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_views');
    }
};
