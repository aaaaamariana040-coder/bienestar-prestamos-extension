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
        Schema::create('informes_guardados', function (Blueprint $table) {
            $table->id('id_informe');
            $table->string('tipo', 30);
            $table->json('parametros');
            $table->json('datos');
            $table->date('fecha_corte');
            $table->string('generado_por', 20);
            $table->foreign('generado_por')->references('cedula')->on('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tipo', 'fecha_corte']);
            $table->index('fecha_corte');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('informes_guardados');
    }
};
