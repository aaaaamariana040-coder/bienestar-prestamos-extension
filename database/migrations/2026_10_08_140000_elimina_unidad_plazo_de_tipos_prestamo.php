<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * plazo_maximo ya se guardaba en quincenas; unidad_plazo solo decidía cómo
     * mostrarlo (años o meses). El plazo se maneja siempre en quincenas, así que
     * la columna sobra y no hay valores que convertir.
     */
    public function up(): void
    {
        Schema::table('tipos_prestamo', function (Blueprint $table) {
            $table->dropColumn('unidad_plazo');
        });
    }

    /**
     * Restaura la columna con la misma regla que usa la pantalla: años cuando el
     * plazo supera 120 quincenas (5 años), meses en los demás casos.
     */
    public function down(): void
    {
        Schema::table('tipos_prestamo', function (Blueprint $table) {
            $table->enum('unidad_plazo', ['años', 'meses'])->default('meses')->after('plazo_maximo');
        });

        DB::table('tipos_prestamo')->where('plazo_maximo', '>', 120)->update(['unidad_plazo' => 'años']);

        Schema::table('tipos_prestamo', function (Blueprint $table) {
            $table->enum('unidad_plazo', ['años', 'meses'])->change();
        });
    }
};
