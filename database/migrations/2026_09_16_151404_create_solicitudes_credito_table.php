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
        Schema::create('solicitudes_credito', function (Blueprint $table) {
            $table->id('id_solicitud');
            $table->string('cedula_empleado', 20);
            $table->foreign('cedula_empleado')->references('cedula')->on('empleados')->restrictOnDelete();
            $table->foreignId('id_tipo')->constrained('tipos_prestamo', 'id_tipo')->restrictOnDelete();
            $table->decimal('monto_solicitado', 18, 2);
            $table->unsignedInteger('plazo_solicitado');
            $table->date('fecha_solicitud');
            $table->enum('estado', ['Radicada', 'Aprobada', 'Rechazada'])->default('Radicada');
            $table->date('fecha_decision')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_credito');
    }
};
