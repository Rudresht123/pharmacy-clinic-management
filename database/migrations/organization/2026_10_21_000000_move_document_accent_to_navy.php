<?php

use App\Models\Tenant\DocumentTemplateVersion;
use Illuminate\Database\Migrations\Migration;

/**
 * Printed documents moved to a navy-and-teal design.
 *
 * A template still carrying the old seeded indigo was never given a colour by
 * anybody, so it takes the new default. Any other accent was chosen by a
 * clinic and is left exactly as it is.
 */
return new class extends Migration
{
    private const INDIGO = '#2e37a4';

    private const NAVY = '#0b5275';

    public function up(): void
    {
        $this->swap(self::INDIGO, self::NAVY);
    }

    public function down(): void
    {
        $this->swap(self::NAVY, self::INDIGO);
    }

    private function swap(string $from, string $to): void
    {
        DocumentTemplateVersion::query()->each(function (DocumentTemplateVersion $version) use ($from, $to): void {
            $config = $version->config;

            if (strcasecmp((string) data_get($config, 'layout.accent'), $from) !== 0) {
                return;
            }

            data_set($config, 'layout.accent', $to);

            // A colour change from a release, not an edit somebody made.
            $version->forceFill(['config' => $config])->saveQuietly();
        });
    }
};
