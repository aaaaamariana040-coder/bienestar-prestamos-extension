<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RecorreLasRutas;
use Tests\TestCase;

/**
 * Prueba de caracterización: fija lo que cada perfil puede hacer en cada ruta de la
 * aplicación. El Funcionario solo es rechazado (403) en las rutas de usuarios y de
 * auditoría; en todas las demás pasa la autorización (la respuesta puede ser una
 * redirección, un error de validación u otro, pero no un 403). El Administrador pasa
 * la autorización en todas.
 *
 * Se escribió y se corrió contra el código ANTERIOR a los permisos por cuenta, y debe
 * seguir pasando con los permisos por defecto de cada perfil: es la garantía de que
 * nada cambió para quien ya usaba el sistema.
 */
class PermisosCaracterizacionTest extends TestCase
{
    use RecorreLasRutas;
    use RefreshDatabase;

    private function esDeUsuariosOAuditoria(string $nombre): bool
    {
        return str_starts_with($nombre, 'usuarios.') || str_starts_with($nombre, 'auditoria.');
    }

    public function test_el_funcionario_solo_es_rechazado_en_usuarios_y_auditoria(): void
    {
        $funcionario = Usuario::factory()->create();
        $rechazadas = [];
        $total = 0;

        foreach ($this->rutasDeLaAplicacion() as $ruta) {
            $total++;
            $estado = $this->estadoDeLaRuta($funcionario, $ruta);

            if ($this->esDeUsuariosOAuditoria($ruta->getName())) {
                $this->assertSame(403, $estado, "El Funcionario debería recibir 403 en {$ruta->getName()}, recibió {$estado}.");
            } elseif ($estado === 403) {
                $rechazadas[] = $ruta->getName();
            }
        }

        $this->assertGreaterThan(70, $total, 'La prueba debe recorrer todas las rutas con nombre de la aplicación.');
        $this->assertSame([], $rechazadas, 'El Funcionario no debe recibir 403 fuera de usuarios y auditoría.');
    }

    public function test_el_administrador_pasa_la_autorizacion_en_todas_las_rutas(): void
    {
        $administrador = Usuario::factory()->administrador()->create();
        $rechazadas = [];

        foreach ($this->rutasDeLaAplicacion() as $ruta) {
            if ($this->estadoDeLaRuta($administrador, $ruta) === 403) {
                $rechazadas[] = $ruta->getName();
            }
        }

        $this->assertSame([], $rechazadas, 'El Administrador no debe recibir 403 en ninguna ruta.');
    }

    public function test_un_invitado_no_pasa_en_ninguna_ruta_protegida(): void
    {
        $paso = [];

        foreach ($this->rutasDeLaAplicacion() as $ruta) {
            $estado = $this->call($ruta->methods()[0], route($ruta->getName(), $this->parametrosDeLaRuta($ruta)))->getStatusCode();

            if ($estado !== 302 && $estado !== 419) {
                $paso[] = "{$ruta->getName()} ({$estado})";
            }
        }

        $this->assertSame([], $paso, 'Sin sesión toda ruta debe redirigir al inicio de sesión.');
    }
}
