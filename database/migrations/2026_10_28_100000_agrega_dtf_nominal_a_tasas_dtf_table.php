<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `valor` pasa a ser el DTF efectivo anual (%), calculado a partir del
     * nominal trimestre anticipado que se digita en `dtf_nominal` (%). Los
     * registros que ya existieran conservan su `valor` y se les calcula el
     * nominal equivalente: TA = 4 × (1 − (1 + EA)^(−1/4)).
     */
    public function up(): void
    {
        Schema::table('tasas_dtf', function (Blueprint $table) {
            $table->decimal('dtf_nominal', 7, 4)->nullable()->after('fecha_vigencia');
        });

        foreach (DB::table('tasas_dtf')->get(['id_tasa_dtf', 'valor']) as $fila) {
            $nominal = 4 * (1 - (1 + (float) $fila->valor / 100) ** (-1 / 4)) * 100;

            DB::table('tasas_dtf')->where('id_tasa_dtf', $fila->id_tasa_dtf)->update(['dtf_nominal' => round($nominal, 4)]);
        }
    }

    public function down(): void
    {
        Schema::table('tasas_dtf', function (Blueprint $table) {
            $table->dropColumn('dtf_nominal');
        });
    }
};
