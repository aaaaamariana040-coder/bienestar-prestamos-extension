<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasa efectiva anual (porcentaje) con la que se liquidó el préstamo, ya
     * con los puntos adicionales en vehículo. Los préstamos existentes quedan
     * en null y no se recalculan.
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->decimal('tasa_efectiva_anual', 7, 4)->nullable()->after('id_tasa');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn('tasa_efectiva_anual');
        });
    }
};
