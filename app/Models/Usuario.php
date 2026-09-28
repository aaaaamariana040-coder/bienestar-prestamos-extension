<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['cedula', 'nombre_completo', 'contrasena_hash', 'rol', 'habilitado'])]
#[Hidden(['contrasena_hash', 'remember_token'])]
class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory;

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
     * @return BelongsTo<Empleado, $this>
     */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'cedula', 'cedula');
    }
}
