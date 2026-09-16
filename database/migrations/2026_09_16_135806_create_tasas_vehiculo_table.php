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
        Schema::create('tasas_vehiculo', function (Blueprint $table) {
            $table->id('id_tasa_vehiculo');
            $table->unsignedTinyInteger('plazo_anios');
            $table->boolean('es_primera_solicitud');
            $table->decimal('puntos_adicionales_ipc', 5, 2);
            $table->timestamps();

            $table->unique(['plazo_anios', 'es_primera_solicitud']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasas_vehiculo');
    }
};
