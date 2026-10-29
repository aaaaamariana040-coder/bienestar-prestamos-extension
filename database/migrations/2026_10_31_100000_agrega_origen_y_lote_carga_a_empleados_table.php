<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca los empleados creados por la carga masiva: origen = 'carga_inicial' y el identificador
     * del lote. Los empleados existentes quedan sin marca (nulos).
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('origen', 30)->nullable()->after('capacidad_endeudamiento');
            $table->string('lote_carga', 40)->nullable()->index()->after('origen');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropIndex(['lote_carga']);
            $table->dropColumn(['origen', 'lote_carga']);
        });
    }
};
