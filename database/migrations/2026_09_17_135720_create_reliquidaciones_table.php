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
        Schema::create('reliquidaciones', function (Blueprint $table) {
            $table->id('id_reliquidacion');
            $table->foreignId('id_prestamo')->constrained('prestamos', 'id_prestamo')->restrictOnDelete();
            $table->enum('motivo', ['Abono extraordinario', 'Cambio de condiciones', 'Retiro']);
            $table->decimal('saldo_anterior', 18, 2);
            $table->decimal('saldo_nuevo', 18, 2);
            $table->decimal('tasa_aplicada', 7, 4)->nullable();
            $table->date('fecha');
            $table->string('cedula_usu', 20);
            $table->foreign('cedula_usu')->references('cedula')->on('usuarios')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reliquidaciones');
    }
};
