<?php

namespace App\Models;

use App\Enums\RolUsuario;
use App\Services\PermisosUsuarioService;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use LogicException;
use RuntimeException;

#[Fillable(['cedula', 'nombre', 'apellido', 'contrasena_hash', 'rol', 'permisos', 'habilitado', 'es_principal'])]
#[Hidden(['contrasena_hash', 'remember_token'])]
class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory;

    /**
     * Cédula reservada del usuario «Sistema», a cuyo nombre se registran las
     * acciones automáticas. Nunca puede iniciar sesión.
     */
    public const CEDULA_SISTEMA = '0000000000';

    protected $primaryKey = 'cedula';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contrasena_hash' => 'hashed',
            'habilitado' => 'boolean',
            'es_principal' => 'boolean',
            'rol' => RolUsuario::class,
            'permisos' => 'array',
        ];
    }

    /**
     * El usuario «Sistema». Se crea con SistemaUsuarioSeeder.
     */
    public static function sistema(): self
    {
        return static::find(self::CEDULA_SISTEMA)
            ?? throw new RuntimeException('No existe el usuario Sistema: corre php artisan db:seed --class=SistemaUsuarioSeeder.');
    }

    /**
     * El usuario «Sistema» está protegido: no se lista, no se edita, no se
     * habilita ni se elimina (ver UsuarioPolicy y el evento deleting).
     */
    public function esSistema(): bool
    {
        return $this->cedula === self::CEDULA_SISTEMA;
    }

    /**
     * Excluye al usuario «Sistema» de los listados de gestión de cuentas.
     *
     * @param  Builder<Usuario>  $consulta
     */
    public function scopeSinSistema(Builder $consulta): void
    {
        $consulta->where('cedula', '!=', self::CEDULA_SISTEMA);
    }

    protected static function booted(): void
    {
        // Una cuenta que nace sin lista de permisos (seeders, consola…) recibe los de su perfil.
        static::creating(function (Usuario $usuario): void {
            if (! array_key_exists('permisos', $usuario->getAttributes())) {
                $usuario->permisos = app(PermisosUsuarioService::class)->defaultsPara($usuario->rol);
            }
        });

        // Solo puede haber un administrador principal.
        static::saving(function (Usuario $usuario): void {
            if ($usuario->es_principal && $usuario->isDirty('es_principal') && static::where('es_principal', true)->where('cedula', '!=', $usuario->cedula)->exists()) {
                throw new LogicException('Ya existe un administrador principal: solo puede haber uno.');
            }
        });

        static::deleting(function (Usuario $usuario): void {
            if ($usuario->esSistema()) {
                throw new LogicException('No se puede eliminar al usuario Sistema.');
            }
        });
    }

    public function getAuthPassword(): string
    {
        return $this->contrasena_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'contrasena_hash';
    }

    /**
     * El administrador principal: la única cuenta con rol Administrador que gestiona
     * usuarios y consulta la auditoría.
     */
    public function esPrincipal(): bool
    {
        return $this->es_principal && $this->rol === RolUsuario::Administrador;
    }

    /**
     * Permisos asignados a la cuenta, sin repetidos y solo los que existen en el
     * catálogo (config/permisos.php). Los exclusivos del administrador principal
     * (Usuarios y Auditoría) los tiene siempre el principal, aunque la lista guardada
     * no los traiga, y ninguna otra cuenta, aunque la lista los traiga. No mira si la
     * cuenta está habilitada.
     *
     * @return list<string>
     */
    public function permisosAsignados(): array
    {
        $catalogo = array_keys(config('permisos.catalogo'));
        $exclusivos = config('permisos.solo_administrador');
        $principal = $this->esPrincipal();

        return array_values(array_filter(
            $catalogo,
            fn (string $permiso): bool => in_array($permiso, $exclusivos, true)
                ? $principal
                : in_array($permiso, (array) $this->permisos, true),
        ));
    }

    /**
     * ¿Puede esta cuenta hacer lo que describe el permiso? Una cuenta
     * deshabilitada no tiene ninguno. Es lo que leen los Gates, las policies y el menú.
     */
    public function tienePermiso(string $permiso): bool
    {
        return $this->habilitado && in_array($permiso, $this->permisosAsignados(), true);
    }

    /**
     * Cuenta habilitada con al menos un permiso operativo (cualquiera salvo los
     * exclusivos del Administrador). Para saber si la cuenta puede hacer algo
     * en el sistema; lo concreto se decide con tienePermiso().
     */
    public function puedeOperar(): bool
    {
        return $this->habilitado
            && array_diff($this->permisosAsignados(), config('permisos.solo_administrador')) !== [];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->nombre.' '.$this->apellido));
    }
}
