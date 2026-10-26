<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La primera cuota corre desde el desembolso. `pendiente_desembolso` marca los préstamos
 * aprobados con la regla nueva que aún no se desembolsan (sus cuotas no se cobran);
 * `inicio_primera_cuota` guarda la fecha de la primera cuota fijada al desembolsar. Los
 * préstamos anteriores quedan como estaban: sin marca y sin fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->boolean('pendiente_desembolso')->default(false)->after('estado');
            $table->date('inicio_primera_cuota')->nullable()->after('pendiente_desembolso');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn(['pendiente_desembolso', 'inicio_primera_cuota']);
        });
    }
};
