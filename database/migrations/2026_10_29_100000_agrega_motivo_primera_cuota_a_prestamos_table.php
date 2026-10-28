<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando el funcionario elige para la primera cuota una quincena distinta a la propuesta por
 * el cierre de novedades, el motivo queda en el préstamo. Nulo si se aceptó la propuesta y en
 * los préstamos anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->string('motivo_primera_cuota', 500)->nullable()->after('inicio_primera_cuota');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn('motivo_primera_cuota');
        });
    }
};
