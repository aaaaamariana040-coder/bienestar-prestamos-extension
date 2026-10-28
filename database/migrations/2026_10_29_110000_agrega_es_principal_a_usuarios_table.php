<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los permisos de Usuarios y Auditoría son exclusivos del administrador principal. La
 * columna marca esa cuenta; se asigna al administrador activo más antiguo (menor fecha de
 * creación y, a igualdad, menor cédula) que no sea el usuario de sistema. Solo hay uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('es_principal')->default(false)->after('habilitado');
        });

        $principal = DB::table('usuarios')
            ->where('rol', 'ADMINISTRADOR')
            ->where('habilitado', true)
            ->where('cedula', '!=', '0000000000')
            ->orderBy('created_at')
            ->orderBy('cedula')
            ->value('cedula');

        if ($principal !== null) {
            DB::table('usuarios')->where('cedula', $principal)->update(['es_principal' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('es_principal');
        });
    }
};
