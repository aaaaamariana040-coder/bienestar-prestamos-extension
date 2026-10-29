<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca los préstamos creados por la carga de cartera vigente: origen = 'carga_inicial' y el
     * identificador del lote. Los préstamos existentes quedan sin marca (nulos).
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->string('origen', 30)->nullable()->after('motivo_primera_cuota');
            $table->string('lote_carga', 40)->nullable()->index()->after('origen');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropIndex(['lote_carga']);
            $table->dropColumn(['origen', 'lote_carga']);
        });
    }
};
