<?php

namespace App\Console\Commands;

use App\Models\Platform\Module;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Reconciles the `modules` catalogue with the code registry.
 *
 * Safe to run on every deploy: it upserts by key and never deletes. A module
 * dropped from the registry is deactivated rather than removed, because
 * organizations may still hold entitlements pointing at it and a foreign key
 * that vanishes takes their billing history with it.
 */
class SyncModulesCommand extends Command
{
    protected $signature = 'modules:sync';

    protected $description = 'Sync the module catalogue from App\Support\Modules\ModuleRegistry';

    public function handle(): int
    {
        $seen = [];

        foreach (ModuleRegistry::all() as $order => $module) {
            Module::updateOrCreate(
                ['key' => $module['key']],
                [
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'group' => $module['group'],
                    'icon' => $module['icon'],
                    'is_core' => $module['is_core'],
                    'sort_order' => $order,
                    'is_active' => true,
                ]
            );

            $seen[] = $module['key'];
        }

        $retired = Module::whereNotIn('key', $seen)->where('is_active', true)->get();

        foreach ($retired as $module) {
            $module->update(['is_active' => false]);
            $this->warn("Retired: {$module->key} (no longer in the registry)");
        }

        $this->info(count($seen).' modules in the catalogue, '.$retired->count().' retired.');

        return self::SUCCESS;
    }
}
