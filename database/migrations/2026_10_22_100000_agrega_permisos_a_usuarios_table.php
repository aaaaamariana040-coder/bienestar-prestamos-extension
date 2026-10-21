<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permisos por cuenta: agrega usuarios.permisos (lista JSON de nombres) y se la
     * asigna a las cuentas que ya existen según su rol, de modo que nada cambia.
     *
     * Las listas están escritas aquí a propósito: una migración debe dar siempre el
     * mismo resultado aunque después se cambien los defaults de config/permisos.php.
     */
    private const FUNCIONARIO = [
        'empleados.ver', 'empleados.gestionar', 'empleados.eliminar', 'empleados.registrar-retiro',
        'modalidades.ver', 'modalidades.gestionar', 'presupuesto.ver', 'presupuesto.gestionar', 'smmlv.ver', 'smmlv.gestionar',
        'solicitudes.ver', 'solicitudes.registrar', 'solicitudes.eliminar-documentos', 'solicitudes.aprobar', 'solicitudes.rechazar',
        'solicitudes.enviar-comite', 'simulador.usar', 'proveedores.ver', 'proveedores.gestionar',
        'prestamos.ver', 'desembolsos.registrar', 'pagos.registrar-cuota', 'pagos.registrar-abono', 'pagos.cancelar-prestamo',
        'quincenas.ver', 'quincenas.confirmar',
        'reportes.ver', 'informes.guardar',
    ];

    private const SOLO_ADMINISTRADOR = ['usuarios.gestionar', 'auditoria.ver'];

    private const CEDULA_SISTEMA = '0000000000';

    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->json('permisos')->nullable()->after('rol');
        });

        DB::table('usuarios')->update(['permisos' => json_encode([])]);

        DB::table('usuarios')->where('rol', 'FUNCIONARIO_BIENESTAR')->where('cedula', '!=', self::CEDULA_SISTEMA)
            ->update(['permisos' => json_encode(self::FUNCIONARIO)]);

        DB::table('usuarios')->where('rol', 'ADMINISTRADOR')
            ->update(['permisos' => json_encode([...self::SOLO_ADMINISTRADOR, ...self::FUNCIONARIO])]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('permisos');
        });
    }
};
