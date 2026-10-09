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
        Schema::create('tasas_dtf', function (Blueprint $table) {
            $table->id('id_tasa_dtf');
            $table->date('fecha_vigencia')->unique();
            $table->decimal('valor', 7, 4);
            $table->string('fuente_normativa', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasas_dtf');
    }
};
