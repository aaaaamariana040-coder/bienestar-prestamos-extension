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
        Schema::create('cuotas', function (Blueprint $table) {
            $table->id('id_cuota');
            $table->foreignId('id_prestamo')->constrained('prestamos', 'id_prestamo')->cascadeOnDelete();
            $table->unsignedInteger('numero_cuota');
            $table->date('fecha_vencimiento');
            $table->decimal('valor_capital', 18, 2);
            $table->decimal('valor_interes', 18, 2);
            $table->decimal('valor_total', 18, 2);
            $table->enum('estado', ['Pendiente', 'Pagada', 'Vencida'])->default('Pendiente');
            $table->timestamps();

            $table->unique(['id_prestamo', 'numero_cuota']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuotas');
    }
};
