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
        Schema::create('abonos_cesantias', function (Blueprint $table) {
            $table->id('id_abono');
            $table->foreignId('id_prestamo')->constrained('prestamos', 'id_prestamo')->restrictOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->decimal('porcentaje', 5, 2)->default(50);
            $table->decimal('monto', 18, 2);
            $table->date('fecha_abono');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abonos_cesantias');
    }
};
