<?php

namespace App\Models\Tenant;

use App\Support\Clinic\ClinicEvents;
use App\Support\Documents\DocumentTypes;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "On this event, at this branch, make this document."
 *
 * A row is a yes — there is no switch on it. A null branch means every
 * branch. The automation reads these through App\Services\Documents\
 * DocumentRules, which maps them onto the value object of the same name; the
 * row itself is never handed round.
 */
class DocumentRule extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $table = 'document_rules';

    protected $fillable = [
        'event_key',
        'document_type',
        'location_id',
        'created_by',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function historyLabel(): ?string
    {
        $type = DocumentTypes::find((string) $this->document_type);

        return ClinicEvents::label((string) $this->event_key).' → '.($type['name'] ?? $this->document_type);
    }
}
