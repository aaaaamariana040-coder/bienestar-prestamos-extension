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
        Schema::create('tasas_int', function (Blueprint $table) {
            $table->id('id_tasa');
            $table->foreignId('id_tipo')->constrained('tipos_prestamo', 'id_tipo')->restrictOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->decimal('tasa_anual', 7, 4);
            $table->string('fuente_normativa', 100)->nullable();
            $table->timestamps();

            $table->unique(['id_tipo', 'anio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasas_int');
    }
};
