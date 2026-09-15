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
        Schema::create('empleados', function (Blueprint $table) {
            $table->string('cedula', 20)->primary();
            $table->string('nombre_completo', 150);
            $table->string('tipo_vinculacion', 30);
            $table->string('cargo', 100)->nullable();
            $table->string('dependencia', 100)->nullable();
            $table->decimal('salario', 18, 2);
            $table->date('fecha_vinculacion')->nullable();
            $table->string('estado', 20)->default('Activo');
            $table->decimal('capacidad_endeudamiento', 18, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
