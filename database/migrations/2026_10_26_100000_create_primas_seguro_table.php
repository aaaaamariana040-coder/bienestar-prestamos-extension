<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prima de los seguros por año. El simulador la toma de aquí para que cambie
     * sin tocar código. Los valores iniciales son los que dio Bienestar: vida de
     * deudores 0,0003231 y incendio y terremoto sin definir (0).
     */
    private const TASA_VIDA_DEUDORES = '0.0003231';

    public function up(): void
    {
        Schema::create('primas_seguro', function (Blueprint $table) {
            $table->id('id_prima');
            $table->string('concepto', 30);
            $table->unsignedSmallInteger('anio');
            $table->decimal('tasa', 10, 8);
            $table->timestamps();

            $table->unique(['concepto', 'anio']);
        });

        $ahora = now();

        foreach ([$ahora->year, $ahora->year - 1] as $anio) {
            DB::table('primas_seguro')->insert([
                ['concepto' => 'vida_deudores', 'anio' => $anio, 'tasa' => self::TASA_VIDA_DEUDORES, 'created_at' => $ahora, 'updated_at' => $ahora],
                ['concepto' => 'incendio_terremoto', 'anio' => $anio, 'tasa' => '0.0000000', 'created_at' => $ahora, 'updated_at' => $ahora],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('primas_seguro');
    }
};
