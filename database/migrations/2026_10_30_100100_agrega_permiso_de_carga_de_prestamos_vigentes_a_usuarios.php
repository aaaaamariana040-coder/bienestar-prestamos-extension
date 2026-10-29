<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CEDULA_SISTEMA = '0000000000';

    private const PERMISO = 'prestamos.cargar_vigentes';

    /**
     * Las cuentas de Administrador que ya existen reciben el permiso de cargar préstamos
     * vigentes; el Funcionario no lo recibe.
     */
    public function up(): void
    {
        $cuentas = DB::table('usuarios')->where('rol', 'ADMINISTRADOR')->where('cedula', '!=', self::CEDULA_SISTEMA)->get(['cedula', 'permisos']);

        foreach ($cuentas as $cuenta) {
            $permisos = array_values(array_unique([...$this->permisos($cuenta->permisos), self::PERMISO]));

            DB::table('usuarios')->where('cedula', $cuenta->cedula)->update(['permisos' => json_encode($permisos)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('usuarios')->get(['cedula', 'permisos']) as $usuario) {
            $permisos = array_values(array_diff($this->permisos($usuario->permisos), [self::PERMISO]));

            DB::table('usuarios')->where('cedula', $usuario->cedula)->update(['permisos' => json_encode($permisos)]);
        }
    }

    /**
     * @return list<string>
     */
    private function permisos(mixed $json): array
    {
        $permisos = is_string($json) ? json_decode($json, true) : null;

        return is_array($permisos) ? array_values($permisos) : [];
    }
};
