<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quién originó el pago: una persona desde la pantalla (Manual) o el
     * proceso automático de la quincena (Automático). Los pagos que ya
     * existen quedan como Manual.
     */
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->enum('origen', ['Manual', 'Automático'])->default('Manual')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
