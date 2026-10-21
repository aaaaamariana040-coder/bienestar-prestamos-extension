<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un registro por cada correo enviado (o simulado) de una solicitud al
     * comité. Los intentos fallidos no se guardan aquí, solo en la auditoría.
     */
    public function up(): void
    {
        Schema::create('envios_comite', function (Blueprint $table) {
            $table->id('id_envio');
            $table->foreignId('id_solicitud')->constrained('solicitudes_credito', 'id_solicitud')->restrictOnDelete();
            $table->string('cedula_usu', 20);
            $table->foreign('cedula_usu')->references('cedula')->on('usuarios')->restrictOnDelete();
            $table->timestamp('enviado_en');
            $table->json('destinatarios');
            $table->json('copia');
            $table->string('asunto', 255);
            $table->json('adjuntos');
            $table->json('datos');
            $table->string('mailer', 50);
            $table->boolean('simulado')->default(false);
            $table->boolean('es_reenvio')->default(false);
            $table->timestamps();

            $table->index(['id_solicitud', 'enviado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_comite');
    }
};
