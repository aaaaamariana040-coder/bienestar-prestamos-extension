<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca las reliquidaciones que hizo el sistema solo, sin elección del
     * operador: un abono extraordinario igual o menor al umbral baja el plazo
     * con la misma cuota. Falso en las reliquidaciones anteriores.
     */
    public function up(): void
    {
        Schema::table('reliquidaciones', function (Blueprint $table) {
            $table->boolean('automatica')->default(false)->after('opcion_elegida');
        });
    }

    public function down(): void
    {
        Schema::table('reliquidaciones', function (Blueprint $table) {
            $table->dropColumn('automatica');
        });
    }
};
