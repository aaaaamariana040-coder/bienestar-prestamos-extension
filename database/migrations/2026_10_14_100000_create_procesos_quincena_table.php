<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de cada proceso de quincena que se ejecuta en serio. La
     * restricción única impide dos procesos reales de la misma quincena del
     * mismo año; la simulación nunca se guarda.
     */
    public function up(): void
    {
        Schema::create('procesos_quincena', function (Blueprint $table) {
            $table->id('id_proceso');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('numero_quincena');
            $table->enum('tipo', ['Simulación', 'Real']);
            $table->enum('estado', ['Pendiente de confirmación', 'Completado']);
            $table->enum('modo_marcado', ['confirmar', 'automatico']);
            $table->string('cedula_usu', 20);
            $table->foreign('cedula_usu')->references('cedula')->on('usuarios')->restrictOnDelete();
            $table->dateTime('iniciado_en');
            $table->dateTime('finalizado_en')->nullable();
            $table->json('resumen')->nullable();
            $table->timestamps();

            $table->unique(['anio', 'numero_quincena', 'tipo'], 'procesos_quincena_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procesos_quincena');
    }
};
