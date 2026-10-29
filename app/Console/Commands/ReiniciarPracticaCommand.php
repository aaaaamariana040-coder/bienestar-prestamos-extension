<?php

namespace App\Console\Commands;

use Database\Seeders\PracticaSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('practica:reiniciar')]
#[Description('BORRA toda la base y vuelve a cargar los datos de práctica ficticios (solo con PRACTICA_HABILITADA=true y fuera de producción)')]
class ReiniciarPracticaCommand extends Command
{
    public const CONFIRMACION = 'REINICIAR';

    public function handle(): int
    {
        try {
            PracticaSeeder::verificarHabilitada();
        } catch (RuntimeException $excepcion) {
            $this->error($excepcion->getMessage());

            return self::FAILURE;
        }

        $this->warn('Esto BORRA TODOS los datos de la base «'.config('database.connections.'.config('database.default').'.database').'» y carga los datos de práctica.');

        if ($this->ask('Para continuar escriba '.self::CONFIRMACION) !== self::CONFIRMACION) {
            $this->error('Confirmación incorrecta: no se hizo ningún cambio.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => PracticaSeeder::class, '--force' => true]);

        if (! in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->warn('MAIL_MAILER no es «log»: el envío al comité sería real. Use MAIL_MAILER=log en este equipo.');
        }

        $this->info('Práctica reiniciada. Usuarios y contraseñas temporales: docs/guia-practica.md.');

        return self::SUCCESS;
    }
}
