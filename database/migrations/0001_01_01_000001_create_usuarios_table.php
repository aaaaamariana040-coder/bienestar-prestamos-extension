<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->string('cedula', 20)->primary();
            $table->foreign('cedula')->references('cedula')->on('empleados')->restrictOnDelete();
            $table->string('nombre_completo', 150);
            $table->string('contrasena_hash', 255);
            $table->string('rol', 30);
            $table->boolean('habilitado')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Nombre de columna fijo por Illuminate\Session\DatabaseSessionHandler; guarda la cedula, no un id numerico.
            $table->string('user_id', 20)->nullable()->index();
            $table->foreign('user_id')->references('cedula')->on('usuarios')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('usuarios');
    }
};
