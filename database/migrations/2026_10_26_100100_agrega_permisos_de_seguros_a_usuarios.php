<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CEDULA_SISTEMA = '0000000000';

    /**
     * Las cuentas que ya existen reciben los permisos nuevos de Seguros: el
     * Administrador ve y gestiona; el Funcionario solo ve.
     */
    public function up(): void
    {
        $this->agregar('ADMINISTRADOR', ['seguros.ver', 'seguros.gestionar']);
        $this->agregar('FUNCIONARIO_BIENESTAR', ['seguros.ver']);
    }

    public function down(): void
    {
        foreach (DB::table('usuarios')->get(['cedula', 'permisos']) as $usuario) {
            $permisos = array_values(array_diff($this->permisos($usuario->permisos), ['seguros.ver', 'seguros.gestionar']));

            DB::table('usuarios')->where('cedula', $usuario->cedula)->update(['permisos' => json_encode($permisos)]);
        }
    }

    /**
     * @param  list<string>  $nuevos
     */
    private function agregar(string $rol, array $nuevos): void
    {
        $cuentas = DB::table('usuarios')->where('rol', $rol)->where('cedula', '!=', self::CEDULA_SISTEMA)->get(['cedula', 'permisos']);

        foreach ($cuentas as $cuenta) {
            $permisos = array_values(array_unique([...$this->permisos($cuenta->permisos), ...$nuevos]));

            DB::table('usuarios')->where('cedula', $cuenta->cedula)->update(['permisos' => json_encode($permisos)]);
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
