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
        Schema::create('presupuestos_anuales', function (Blueprint $table) {
            $table->id('id_presupuesto');
            $table->unsignedSmallInteger('anio');
            $table->foreignId('id_tipo')->constrained('tipos_prestamo', 'id_tipo')->restrictOnDelete();
            $table->decimal('monto_asignado', 18, 2);
            $table->decimal('monto_comprometido', 18, 2)->default(0);
            $table->decimal('monto_disponible', 18, 2);
            $table->timestamps();

            $table->unique(['anio', 'id_tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presupuestos_anuales');
    }
};
