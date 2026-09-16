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
        Schema::create('presupuesto_historial', function (Blueprint $table) {
            $table->id('id_historial');
            $table->foreignId('id_presupuesto')->constrained('presupuestos_anuales', 'id_presupuesto')->restrictOnDelete();
            $table->enum('campo', ['monto_asignado', 'monto_comprometido']);
            $table->decimal('monto_anterior', 18, 2);
            $table->decimal('monto_nuevo', 18, 2);
            $table->text('motivo');
            $table->string('cedula_usu', 20);
            $table->foreign('cedula_usu')->references('cedula')->on('usuarios')->restrictOnDelete();
            $table->dateTime('fecha');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presupuesto_historial');
    }
};
