<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The module catalogue.
 *
 * Delegates to `modules:sync` rather than repeating the list, so a fresh
 * database and a deployed one are reconciled by the same code — a seeder
 * that drifts from the command is a catalogue that depends on how the
 * environment was built.
 */
class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('modules:sync');
    }
}
