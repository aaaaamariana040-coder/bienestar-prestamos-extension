<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nombre y apellido confirmados por Bienestar para los usuarios que existían
     * al separar usuarios de empleados. No se divide nombre_completo con ninguna
     * regla automática: un usuario fuera de esta lista detiene la migración.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NOMBRES_CONFIRMADOS = [
        '1000000000' => ['Administrador', 'Bienestar'],
        '1000000001' => ['Funcionario', 'Prueba'],
    ];

    public function up(): void
    {
        $usuarios = DB::table('usuarios')->get(['cedula', 'nombre_completo']);

        foreach ($usuarios as $usuario) {
            $confirmado = self::NOMBRES_CONFIRMADOS[$usuario->cedula] ?? null;

            if ($confirmado === null || trim($confirmado[0]) === '' || trim($confirmado[1]) === '') {
                throw new RuntimeException("El usuario {$usuario->cedula} ({$usuario->nombre_completo}) no tiene nombre y apellido confirmados. Agrégalo a NOMBRES_CONFIRMADOS antes de migrar.");
            }
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre', 80)->nullable()->after('cedula');
            $table->string('apellido', 80)->nullable()->after('nombre');
        });

        foreach ($usuarios as $usuario) {
            [$nombre, $apellido] = self::NOMBRES_CONFIRMADOS[$usuario->cedula];

            DB::table('usuarios')->where('cedula', $usuario->cedula)->update(['nombre' => $nombre, 'apellido' => $apellido]);
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre', 80)->nullable(false)->change();
            $table->string('apellido', 80)->nullable(false)->change();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['cedula']);
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('nombre_completo');
        });
    }

    public function down(): void
    {
        $huerfanos = DB::table('usuarios')
            ->whereNotIn('cedula', DB::table('empleados')->select('cedula'))
            ->pluck('cedula');

        if ($huerfanos->isNotEmpty()) {
            throw new RuntimeException('No se puede revertir: estos usuarios no tienen un empleado con la misma cédula y la llave foránea no se podría restaurar: '.$huerfanos->implode(', ').'.');
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre_completo', 150)->nullable()->after('cedula');
        });

        foreach (DB::table('usuarios')->get(['cedula', 'nombre', 'apellido']) as $usuario) {
            DB::table('usuarios')->where('cedula', $usuario->cedula)->update([
                'nombre_completo' => trim($usuario->nombre.' '.$usuario->apellido),
            ]);
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre_completo', 150)->nullable(false)->change();
            $table->dropColumn(['nombre', 'apellido']);
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreign('cedula')->references('cedula')->on('empleados')->restrictOnDelete();
        });
    }
};
