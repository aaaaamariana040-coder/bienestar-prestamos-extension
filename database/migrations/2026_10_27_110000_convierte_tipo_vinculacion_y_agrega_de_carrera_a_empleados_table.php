<?php

use App\Enums\TipoVinculacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Empleado» pasa a vinculado y «Docente» a docente. Cualquier otro valor
     * desconocido se deja en vinculado y se reporta en el registro; no se
     * pierde ninguna fila.
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->boolean('de_carrera')->default(false)->after('tipo_vinculacion');
        });

        DB::table('empleados')->where('tipo_vinculacion', 'Empleado')->update(['tipo_vinculacion' => TipoVinculacion::Vinculado->value]);
        DB::table('empleados')->where('tipo_vinculacion', 'Docente')->update(['tipo_vinculacion' => TipoVinculacion::Docente->value]);

        $desconocidos = DB::table('empleados')->whereNotIn('tipo_vinculacion', TipoVinculacion::valores())->get(['cedula', 'tipo_vinculacion']);

        if ($desconocidos->isNotEmpty()) {
            Log::warning('Migración tipo_vinculacion: valores desconocidos dejados en «vinculado».', [
                'empleados' => $desconocidos->map(fn ($fila) => "{$fila->cedula}: {$fila->tipo_vinculacion}")->all(),
            ]);

            DB::table('empleados')->whereIn('cedula', $desconocidos->pluck('cedula'))->update(['tipo_vinculacion' => TipoVinculacion::Vinculado->value]);
        }
    }

    /**
     * Docente ocasional y jubilado no existían antes: vuelven a «Empleado».
     */
    public function down(): void
    {
        DB::table('empleados')->where('tipo_vinculacion', TipoVinculacion::Docente->value)->update(['tipo_vinculacion' => 'Docente']);
        DB::table('empleados')->whereNotIn('tipo_vinculacion', ['Docente'])->update(['tipo_vinculacion' => 'Empleado']);

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('de_carrera');
        });
    }
};
