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
        Schema::create('tipos_prestamo', function (Blueprint $table) {
            $table->id('id_tipo');
            $table->string('nombre', 100)->unique();
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('plazo_maximo');
            $table->enum('unidad_plazo', ['años', 'meses']);
            $table->boolean('habilitada')->default(true);
            $table->string('fuente_normativa', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_prestamo');
    }
};
