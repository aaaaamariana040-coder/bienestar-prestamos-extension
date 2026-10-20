<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opción que eligió el operador al reliquidar: 'cuota' (bajar el valor de
     * la cuota) o 'plazo' (bajar el número de cuotas). Nula en reliquidaciones
     * anteriores y en las que no la requieren (retiro).
     */
    public function up(): void
    {
        Schema::table('reliquidaciones', function (Blueprint $table) {
            $table->string('opcion_elegida', 10)->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('reliquidaciones', function (Blueprint $table) {
            $table->dropColumn('opcion_elegida');
        });
    }
};
