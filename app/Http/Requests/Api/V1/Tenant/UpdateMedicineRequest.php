<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Medicine;

/**
 * Editing a medicine.
 *
 * The same rules as adding one, with the medicine being edited excluded
 * from the duplicate checks — otherwise saving it unchanged would clash
 * with itself.
 */
class UpdateMedicineRequest extends StoreMedicineRequest
{
    protected function medicine(): ?Medicine
    {
        $medicine = $this->route('medicine');

        return $medicine instanceof Medicine ? $medicine : null;
    }
}
