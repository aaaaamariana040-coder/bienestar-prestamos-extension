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
        Schema::create('prestamos', function (Blueprint $table) {
            $table->id('id_prestamo');
            $table->foreignId('id_solicitud')->nullable()->constrained('solicitudes_credito', 'id_solicitud')->restrictOnDelete();
            $table->string('cedula_empleado', 20);
            $table->foreign('cedula_empleado')->references('cedula')->on('empleados')->restrictOnDelete();
            $table->foreignId('id_tipo')->constrained('tipos_prestamo', 'id_tipo')->restrictOnDelete();
            $table->foreignId('id_tasa')->nullable()->constrained('tasas_int', 'id_tasa')->restrictOnDelete();
            $table->decimal('monto_aprobado', 18, 2);
            $table->unsignedInteger('num_cuotas');
            $table->decimal('cuota_periodica', 18, 2);
            $table->date('fecha_autorizacion');
            $table->enum('estado', ['Vigente', 'Cancelado', 'En mora'])->default('Vigente');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prestamos');
    }
};
