<?php

namespace Database\Factories;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cedula' => fake()->unique()->numerify('##########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'contrasena_hash' => static::$password ??= Hash::make('password'),
            'rol' => RolUsuario::FuncionarioBienestar,
            'habilitado' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user has the Administrador role.
     */
    public function administrador(): static
    {
        return $this->state(fn (array $attributes) => [
            'rol' => RolUsuario::Administrador,
        ]);
    }
}
