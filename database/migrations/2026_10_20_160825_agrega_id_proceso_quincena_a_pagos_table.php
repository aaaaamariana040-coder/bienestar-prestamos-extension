<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaza cada pago creado al confirmar o procesar una quincena con su
     * proceso, para poder trazar qué pagos nacieron de él. Los pagos manuales
     * y los anteriores quedan en nulo.
     */
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->foreignId('id_proceso_quincena')
                ->nullable()
                ->after('origen')
                ->constrained('procesos_quincena', 'id_proceso')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_proceso_quincena');
        });
    }
};
