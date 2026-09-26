<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\DocumentTemplateVersion;
use App\Services\Modules\ModuleAccess;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Documents\DocumentTypes;
use App\Support\Documents\TemplateConfig;
use Illuminate\Support\Facades\DB;

/**
 * Give an organization a printable letterhead on day one.
 *
 * Without this the module is switched on and prints nothing: every document
 * type refuses until somebody has built and published a template, and the
 * first thing a new clinic would meet is a settings screen with a blank grid.
 * The defaults are deliberately USABLE AS THEY ARE — a template that has to be
 * finished before it prints anything is a template nobody finishes.
 *
 * Seeded as the ORGANIZATION DEFAULT (`location_id` null), so every branch
 * inherits it and a branch that wants its own simply overrides. Published
 * straight away, because an unpublished default is the same as none.
 *
 * A service rather than a migration, for the same reason DefaultRoleSeeder is:
 * which types apply depends on the modules this organization was sold, and
 * that lives in the master database — which a tenant migration cannot read,
 * since the migrator repoints `database.default` at the tenant while it runs.
 *
 * IDEMPOTENT by type. An organization that already has a default for a
 * document keeps theirs; this adds, and never edits or replaces.
 */
class DefaultTemplateSeeder
{
    public function __construct(
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * @return list<string> the document types actually created
     */
    public function seed(Organization $organization): array
    {
        $modules = $this->modules->enabled($organization);
        $connection = TenantConnectionService::CONNECTION;
        $created = [];

        foreach (DocumentTypes::available($modules) as $type) {
            $exists = DocumentTemplate::on($connection)
                ->whereNull('location_id')
                ->where('document_type', $type['key'])
                ->withTrashed()
                ->exists();

            if ($exists) {
                continue;
            }

            /*
             * One transaction per template rather than one for all of them: a
             * type that fails — a placeholder retired between releases, say —
             * should not take the others with it.
             */
            DB::connection($connection)->transaction(function () use ($type, $connection) {
                $template = DocumentTemplate::on($connection)->create([
                    'location_id' => null,
                    'document_type' => $type['key'],
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'status' => DocumentTemplate::ACTIVE,
                ]);

                $version = DocumentTemplateVersion::on($connection)->create([
                    'document_template_id' => $template->getKey(),
                    'version' => 1,
                    'config' => TemplateConfig::defaultsFor($type['key']),
                ]);

                /*
                 * Published with no `published_by`: nobody chose this, the
                 * software shipped it. A name there would credit whoever
                 * happened to provision the organization with writing a
                 * letterhead they never saw.
                 */
                $version->forceFill(['published_at' => now()])->save();

                $template->forceFill(['active_version_id' => $version->getKey()])->save();
            });

            $created[] = $type['key'];
        }

        return $created;
    }
}
