<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un saldo_nuevo nulo indica una reliquidación por retiro cuyo cálculo
     * aún no se ha realizado (pendiente de reglas del Art. 40).
     */
    public function up(): void
    {
        Schema::table('reliquidaciones', function (Blueprint $table) {
            $table->decimal('saldo_nuevo', 18, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reliquidaciones', function (Blueprint $table) {
            $table->decimal('saldo_nuevo', 18, 2)->nullable(false)->change();
        });
    }
};
