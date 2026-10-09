<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos adjuntos a una solicitud de crédito. El archivo vive en el
     * disco privado; aquí solo queda su ruta interna (nombre aleatorio) y los
     * datos del archivo original. Se borran en cascada solo si se borra la
     * solicitud.
     */
    public function up(): void
    {
        Schema::create('documentos_solicitud', function (Blueprint $table) {
            $table->id('id_documento');
            $table->foreignId('id_solicitud')->constrained('solicitudes_credito', 'id_solicitud')->cascadeOnDelete();
            $table->foreignId('id_tipo_documento')->constrained('tipos_documento', 'id_tipo_documento')->restrictOnDelete();
            $table->string('nombre_original', 255);
            $table->string('ruta', 255);
            $table->unsignedInteger('tamano');
            $table->string('tipo_mime', 100);
            $table->string('cedula_usu', 20);
            $table->foreign('cedula_usu')->references('cedula')->on('usuarios')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_solicitud');
    }
};
