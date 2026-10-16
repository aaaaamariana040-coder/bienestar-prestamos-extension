<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use LogicException;
use RuntimeException;

#[Fillable(['cedula', 'nombre', 'apellido', 'contrasena_hash', 'rol', 'habilitado'])]
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
            'rol' => RolUsuario::class,
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
     * Cuenta habilitada con un rol que puede operar el sistema (todo salvo
     * la gestión de usuarios y la consulta de auditoría, que son solo del Administrador).
     */
    public function puedeOperar(): bool
    {
        return $this->habilitado
            && in_array($this->rol, [RolUsuario::Administrador, RolUsuario::FuncionarioBienestar], true);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->nombre.' '.$this->apellido));
    }
}
