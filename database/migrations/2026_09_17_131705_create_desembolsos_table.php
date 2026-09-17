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
        Schema::create('desembolsos', function (Blueprint $table) {
            $table->id('id_desembolso');
            $table->foreignId('id_prestamo')->constrained('prestamos', 'id_prestamo')->restrictOnDelete();
            $table->string('id_proveedor', 20)->nullable();
            $table->foreign('id_proveedor')->references('id_proveedor')->on('proveedores')->restrictOnDelete();
            $table->unsignedTinyInteger('numero_tramo');
            $table->decimal('porcentaje', 5, 2);
            $table->date('fecha')->nullable();
            $table->decimal('valor', 18, 2);
            $table->boolean('requiere_inspeccion')->default(false);
            $table->boolean('inspeccion_aprobada')->nullable();
            $table->enum('estado', ['Pendiente', 'Realizado'])->default('Pendiente');
            $table->timestamps();

            $table->unique(['id_prestamo', 'numero_tramo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('desembolsos');
    }
};
