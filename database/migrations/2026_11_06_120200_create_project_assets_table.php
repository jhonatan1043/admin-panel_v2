<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Nombre con el que la vista referencia el recurso (p. ej. "img/logo.png").
            $table->string('reference');
            $table->string('original_name');
            // Ruta real en el disco de almacenamiento.
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assets');
    }
};
