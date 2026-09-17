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
        Schema::create('pagos', function (Blueprint $table) {
            $table->id('id_pago');
            $table->foreignId('id_prestamo')->constrained('prestamos', 'id_prestamo')->restrictOnDelete();
            $table->foreignId('id_cuota')->nullable()->constrained('cuotas', 'id_cuota')->restrictOnDelete();
            $table->date('fecha_pago');
            $table->decimal('monto', 18, 2);
            $table->enum('tipo', ['Regular', 'Abono extraordinario', 'Cancelación'])->default('Regular');
            $table->decimal('monto_aplicado_interes', 18, 2);
            $table->decimal('monto_aplicado_capital', 18, 2);
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
        Schema::dropIfExists('pagos');
    }
};
