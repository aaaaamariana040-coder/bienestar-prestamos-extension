<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cuantias_smmlv', function (Blueprint $table) {
            $table->id('id_cuantia');
            $table->foreignId('id_tipo')->constrained('tipos_prestamo', 'id_tipo')->restrictOnDelete();
            $table->decimal('tramo_desde_smmlv', 6, 2);
            $table->decimal('tramo_hasta_smmlv', 6, 2)->nullable();
            $table->decimal('cuantia_maxima_smmlv', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuantias_smmlv');
    }
};
